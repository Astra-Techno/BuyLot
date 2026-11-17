<?php

declare(strict_types=1);

namespace App\Core\Database;

abstract class Migration
{
    protected Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    abstract public function up(): void;

    abstract public function down(): void;

    protected function createTable(string $name, callable $callback): void
    {
        $builder = new TableBuilder($name);
        $callback($builder);
        $sql = $builder->toSql();
        $this->connection->execute($sql);
    }

    protected function dropTable(string $name): void
    {
        $this->connection->execute("DROP TABLE IF EXISTS {$name}");
    }

    protected function execute(string $sql, array $params = []): void
    {
        $this->connection->execute($sql, $params);
    }
}
