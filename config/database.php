<?php

declare(strict_types=1);

return [
    'default' => env('DB_CONNECTION', 'mysql'),
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', 'localhost'),
            'port' => (int) env('DB_PORT', 3306),
            'database' => env('DB_NAME', 'collective_commerce'),
            'user' => env('DB_USER', 'root'),
            'password' => env('DB_PASS', ''),
            'charset' => 'utf8mb4',
            'persistent' => env('DB_PERSISTENT', false),
        ],
    ],
];
