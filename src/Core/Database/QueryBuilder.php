<?php

declare(strict_types=1);

namespace App\Core\Database;

class QueryBuilder
{
    private string $table = '';
    private array $select = ['*'];
    private array $where = [];
    private array $bindings = [];
    private array $joins = [];
    private array $orderBy = [];
    private array $groupBy = [];
    private array $limit = [];
    private array $offset = [];

    public function __construct(private Connection $connection) {}

    public function table(string $name): self
    {
        $this->table = $name;
        return $this;
    }

    public function select(string ...$columns): self
    {
        $this->select = $columns;
        return $this;
    }

    public function where(string $column, mixed $operator = '=', mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->where[] = "{$column} {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    public function orWhere(string $column, mixed $operator = '=', mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        if (empty($this->where)) {
            return $this->where($column, $operator, $value);
        }

        $clause = array_pop($this->where);
        $binding = array_pop($this->bindings);

        $this->where[] = "({$clause} OR {$column} {$operator} ?)";
        $this->bindings[] = $binding;
        $this->bindings[] = $value;
        return $this;
    }

    public function join(string $table, string $condition): self
    {
        $this->joins[] = "INNER JOIN {$table} ON {$condition}";
        return $this;
    }

    public function leftJoin(string $table, string $condition): self
    {
        $this->joins[] = "LEFT JOIN {$table} ON {$condition}";
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[] = "{$column} {$direction}";
        return $this;
    }

    public function groupBy(string ...$columns): self
    {
        $this->groupBy = $columns;
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = [$limit];
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = [$offset];
        return $this;
    }

    public function get(): array
    {
        return $this->connection->query($this->toSql(), $this->bindings);
    }

    public function first(): ?array
    {
        $result = $this->limit(1)->get();
        return $result[0] ?? null;
    }

    public function count(): int
    {
        $query = clone $this;
        $query->select = ['COUNT(*) as count'];
        $result = $query->first();
        return (int) ($result['count'] ?? 0);
    }

    public function insert(array $data): bool
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";

        $this->connection->execute($sql, array_values($data));
        return true;
    }

    public function update(array $data): int
    {
        $sets = implode(', ', array_map(fn($col) => "{$col} = ?", array_keys($data)));
        $values = array_values($data);
        $values = array_merge($values, $this->bindings);

        $where = $this->buildWhere();
        $sql = "UPDATE {$this->table} SET {$sets}";
        if ($where) {
            $sql .= " WHERE {$where}";
        }

        return $this->connection->execute($sql, $values);
    }

    public function delete(): int
    {
        $where = $this->buildWhere();
        $sql = "DELETE FROM {$this->table}";
        if ($where) {
            $sql .= " WHERE {$where}";
        }

        return $this->connection->execute($sql, $this->bindings);
    }

    public function toSql(): string
    {
        $sql = 'SELECT ' . implode(', ', $this->select);
        $sql .= " FROM {$this->table}";

        if (!empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        if ($where = $this->buildWhere()) {
            $sql .= " WHERE {$where}";
        }

        if (!empty($this->groupBy)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }

        if (!empty($this->orderBy)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }

        if (!empty($this->limit)) {
            $sql .= ' LIMIT ' . implode(', ', $this->limit);
        }

        if (!empty($this->offset)) {
            $sql .= ' OFFSET ' . implode(', ', $this->offset);
        }

        return $sql;
    }

    private function buildWhere(): string
    {
        return implode(' AND ', $this->where);
    }
}
