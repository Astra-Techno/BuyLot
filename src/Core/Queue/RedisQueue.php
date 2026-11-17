<?php

declare(strict_types=1);

namespace App\Core\Queue;

use App\Core\Cache\RedisCache;

class RedisQueue implements QueueInterface
{
    private const PREFIX = 'queue:';

    public function __construct(private RedisCache $cache) {}

    public function push(string $job, mixed $data = null, string $queue = 'default'): bool
    {
        $payload = [
            'job' => $job,
            'data' => $data,
            'attempts' => 0,
            'created_at' => time(),
        ];

        $key = self::PREFIX . $queue;
        $value = json_encode($payload);

        try {
            $this->cache->redis->lPush($key, $value);
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function pop(string $queue = 'default'): ?array
    {
        $key = self::PREFIX . $queue;

        try {
            $value = $this->cache->redis->lPop($key);

            if ($value === false) {
                return null;
            }

            return json_decode($value, true);
        } catch (\Exception) {
            return null;
        }
    }

    public function getSize(string $queue = 'default'): int
    {
        $key = self::PREFIX . $queue;

        try {
            return (int) $this->cache->redis->lLen($key);
        } catch (\Exception) {
            return 0;
        }
    }
}
