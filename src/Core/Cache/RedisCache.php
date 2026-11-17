<?php

declare(strict_types=1);

namespace App\Core\Cache;

class RedisCache
{
    private ?\Redis $redis = null;
    private bool $available = false;

    public function __construct(
        string $host = 'localhost',
        int $port = 6379,
        int $database = 0,
        ?string $password = null,
    ) {
        if (!class_exists(\Redis::class)) {
            error_log('[RedisCache] ext-redis not installed — running in no-cache mode');
            return;
        }

        try {
            $this->redis = new \Redis();
            $this->redis->connect($host, $port);

            if ($password !== null && $password !== '') {
                $this->redis->auth($password);
            }

            $this->redis->select($database);
            $this->available = true;
        } catch (\Throwable $e) {
            error_log('[RedisCache] Connection failed: ' . $e->getMessage());
            $this->redis = null;
        }
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function get(string $key): mixed
    {
        if (!$this->available) {
            return null;
        }

        $value = $this->redis->get($key);

        if ($value === false) {
            return null;
        }

        return json_decode($value, true);
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        if (!$this->available) {
            return false;
        }

        $encoded = json_encode($value);

        if ($ttl > 0) {
            return $this->redis->setex($key, $ttl, $encoded);
        }

        return $this->redis->set($key, $encoded);
    }

    public function delete(string $key): bool
    {
        if (!$this->available) {
            return false;
        }
        return (bool) $this->redis->del($key);
    }

    public function exists(string $key): bool
    {
        if (!$this->available) {
            return false;
        }
        return $this->redis->exists($key) === 1;
    }

    public function flush(): bool
    {
        if (!$this->available) {
            return false;
        }
        return $this->redis->flushDb();
    }

    public function increment(string $key, int $increment = 1): int|false
    {
        if (!$this->available) {
            return false;
        }
        return $this->redis->incrBy($key, $increment);
    }

    public function decrement(string $key, int $decrement = 1): int|false
    {
        if (!$this->available) {
            return false;
        }
        return $this->redis->decrBy($key, $decrement);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        if (!$this->available) {
            return $callback();
        }

        if ($this->exists($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->set($key, $value, 0);
    }

    public function close(): void
    {
        if ($this->available && $this->redis !== null) {
            $this->redis->close();
        }
    }
}
