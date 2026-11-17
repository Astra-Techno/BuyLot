<?php

declare(strict_types=1);

namespace App\Core\Queue;

interface QueueInterface
{
    public function push(string $job, mixed $data = null, string $queue = 'default'): bool;

    public function pop(string $queue = 'default'): ?array;

    public function getSize(string $queue = 'default'): int;
}
