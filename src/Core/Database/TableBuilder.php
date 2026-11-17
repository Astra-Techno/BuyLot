<?php

declare(strict_types=1);

namespace App\Core\Database;

class TableBuilder
{
    private array $columns = [];
    private array $primaryKey = [];
    private array $foreignKeys = [];
    private array $indices = [];

    public function __construct(private string $name) {}

    public function id(): self
    {
        $this->columns[] = 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        return $this;
    }

    public function string(string $name, int $length = 255): self
    {
        $this->columns[] = "{$name} VARCHAR({$length})";
        return $this;
    }

    public function text(string $name): self
    {
        $this->columns[] = "{$name} TEXT";
        return $this;
    }

    public function integer(string $name): self
    {
        $this->columns[] = "{$name} INT";
        return $this;
    }

    public function bigInteger(string $name): self
    {
        $this->columns[] = "{$name} BIGINT";
        return $this;
    }

    public function decimal(string $name, int $precision = 8, int $scale = 2): self
    {
        $this->columns[] = "{$name} DECIMAL({$precision},{$scale})";
        return $this;
    }

    public function boolean(string $name): self
    {
        $this->columns[] = "{$name} BOOLEAN DEFAULT 0";
        return $this;
    }

    public function timestamp(string $name): self
    {
        $this->columns[] = "{$name} TIMESTAMP";
        return $this;
    }

    public function timestampNullable(string $name): self
    {
        $this->columns[] = "{$name} TIMESTAMP NULL";
        return $this;
    }

    public function dateTime(string $name): self
    {
        $this->columns[] = "{$name} DATETIME";
        return $this;
    }

    public function date(string $name): self
    {
        $this->columns[] = "{$name} DATE";
        return $this;
    }

    public function json(string $name): self
    {
        $this->columns[] = "{$name} JSON";
        return $this;
    }

    public function enum(string $name, array $values): self
    {
        $escaped = implode("', '", $values);
        $this->columns[] = "{$name} ENUM('{$escaped}')";
        return $this;
    }

    public function nullable(): self
    {
        if (!empty($this->columns)) {
            $last = array_pop($this->columns);
            $this->columns[] = $last . ' NULL';
        }
        return $this;
    }

    public function default(mixed $value): self
    {
        if (!empty($this->columns)) {
            $last = array_pop($this->columns);
            if (is_bool($value)) {
                $value = $value ? 1 : 0;
            } elseif (is_string($value)) {
                $value = "'{$value}'";
            }
            $this->columns[] = $last . " DEFAULT {$value}";
        }
        return $this;
    }

    public function unique(): self
    {
        if (!empty($this->columns)) {
            $last = array_pop($this->columns);
            $this->columns[] = $last . ' UNIQUE';
        }
        return $this;
    }

    public function index(): self
    {
        if (!empty($this->columns)) {
            $last = array_pop($this->columns);
            preg_match('/^(\w+)/', $last, $matches);
            if (isset($matches[1])) {
                $this->indices[] = "INDEX idx_{$matches[1]} ({$matches[1]})";
            }
            $this->columns[] = $last;
        }
        return $this;
    }

    public function foreignKey(string $column, string $table, string $references): self
    {
        $this->foreignKeys[] = "FOREIGN KEY ({$column}) REFERENCES {$table}({$references}) ON DELETE CASCADE";
        return $this;
    }

    public function timestamps(): self
    {
        $this->columns[] = "created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
        $this->columns[] = "updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
        return $this;
    }

    public function softDeletes(): self
    {
        $this->columns[] = "deleted_at TIMESTAMP NULL";
        return $this;
    }

    public function toSql(): string
    {
        $columnDefs = implode(",\n    ", $this->columns);
        $allConstraints = array_merge($this->foreignKeys, $this->indices);
        $constraints = !empty($allConstraints) ? ",\n    " . implode(",\n    ", $allConstraints) : '';

        return "CREATE TABLE {$this->name} (\n    {$columnDefs}{$constraints}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }
}
