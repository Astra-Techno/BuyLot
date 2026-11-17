<?php

declare(strict_types=1);

namespace App\Core\Database;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class MigrationRunner
{
    public function __construct(
        private Connection $connection,
        private string $migrationsPath,
    ) {}

    public function run(): void
    {
        $this->createMigrationsTable();
        $pending = $this->getPendingMigrations();

        foreach ($pending as $migration) {
            $this->runMigration($migration);
        }
    }

    public function rollback(int $steps = 1): void
    {
        $this->createMigrationsTable();
        $executed = $this->getExecutedMigrations();
        $executed = array_slice($executed, -$steps);

        foreach (array_reverse($executed) as $migration) {
            $this->rollbackMigration($migration);
        }
    }

    private function createMigrationsTable(): void
    {
        $sql = <<<SQL
            CREATE TABLE IF NOT EXISTS migrations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                batch INT NOT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;

        $this->connection->execute($sql);
    }

    private function getPendingMigrations(): array
    {
        $executed = $this->getExecutedMigrations();
        $available = $this->getAvailableMigrations();

        return array_diff($available, $executed);
    }

    private function getExecutedMigrations(): array
    {
        $result = $this->connection->query('SELECT name FROM migrations ORDER BY name');
        return array_column($result, 'name');
    }

    private function getAvailableMigrations(): array
    {
        $migrations = [];

        if (!is_dir($this->migrationsPath)) {
            return $migrations;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->migrationsPath, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $migrations[] = $file->getBasename('.php');
            }
        }

        sort($migrations);
        return $migrations;
    }

    private function runMigration(string $migration): void
    {
        $filePath = $this->migrationsPath . '/' . $migration . '.php';

        if (!file_exists($filePath)) {
            throw new \RuntimeException("Migration file not found: {$filePath}");
        }

        $className = $this->fileToClassName($migration);
        require_once $filePath;

        if (!class_exists($className)) {
            throw new \RuntimeException("Migration class not found: {$className}");
        }

        $instance = new $className($this->connection);

        try {
            $instance->up();
            $batch = $this->getNextBatch();
            $this->connection->execute(
                'INSERT INTO migrations (name, batch) VALUES (?, ?)',
                [$migration, $batch],
            );
            echo "Migrated: {$migration}\n";
        } catch (\Exception $e) {
            echo "Error migrating {$migration}: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    private function rollbackMigration(string $migration): void
    {
        $filePath = $this->migrationsPath . '/' . $migration . '.php';

        if (!file_exists($filePath)) {
            echo "Migration file not found: {$filePath}\n";
            return;
        }

        $className = $this->fileToClassName($migration);
        require_once $filePath;

        if (!class_exists($className)) {
            echo "Migration class not found: {$className}\n";
            return;
        }

        $instance = new $className($this->connection);

        try {
            $instance->down();
            $this->connection->execute('DELETE FROM migrations WHERE name = ?', [$migration]);
            echo "Rolled back: {$migration}\n";
        } catch (\Exception $e) {
            echo "Error rolling back {$migration}: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    private function getNextBatch(): int
    {
        $result = $this->connection->query('SELECT MAX(batch) as max_batch FROM migrations');
        return ((int) ($result[0]['max_batch'] ?? 0)) + 1;
    }

    private function fileToClassName(string $filename): string
    {
        // Convert "001_create_users" to "CreateUsers"
        $parts = explode('_', $filename);
        array_shift($parts); // Remove numeric prefix

        return 'App\\Migrations\\' . implode('', array_map(ucfirst(...), $parts));
    }
}
