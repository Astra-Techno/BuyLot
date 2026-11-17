<?php

declare(strict_types=1);

return [
    'jwt' => [
        'secret' => env('JWT_SECRET', 'your-secret-key-change-in-production'),
        'expires_in' => (int) env('JWT_EXPIRES_IN', 86400), // 24 hours
    ],
    'redis' => [
        'host' => env('REDIS_HOST', 'localhost'),
        'port' => (int) env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD', null),
        'database' => (int) env('REDIS_DB', 0),
    ],
];
