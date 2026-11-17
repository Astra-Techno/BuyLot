<?php

declare(strict_types=1);

use App\Core\Config\Config;

if (!function_exists('env')) {
    /**
     * Get an environment variable with optional default value.
     */
    function env(string $key, mixed $default = null): mixed
    {
        return Config::env($key, $default);
    }
}

if (!function_exists('config')) {
    /**
     * Get a configuration value.
     */
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}
