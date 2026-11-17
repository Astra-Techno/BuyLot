<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Config\Config;
use App\Core\Database\Connection;

class Bootstrap
{
    public static function init(string $basePath): void
    {
        // Setup error reporting
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');

        // Load configuration
        Config::load($basePath . '/.env', $basePath . '/config');

        // Initialize database
        $dbConfig = Config::get('database.connections.mysql');
        Connection::getInstance($dbConfig);
    }

    public static function getBasePath(): string
    {
        return dirname(__DIR__, 2);
    }
}
