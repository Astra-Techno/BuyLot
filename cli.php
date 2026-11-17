#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Core\Bootstrap;
use App\Core\Database\Connection;
use App\Core\Database\MigrationRunner;

require_once __DIR__ . '/vendor/autoload.php';

$basePath = __DIR__;
Bootstrap::init($basePath);

$command = $argv[1] ?? 'help';
$argument = $argv[2] ?? null;

$dbConfig = \App\Core\Config\Config::get('database.connections.mysql');
$connection = Connection::getInstance($dbConfig);
$runner = new MigrationRunner($connection, $basePath . '/migrations');

match ($command) {
    'migrate' => $runner->run(),
    'rollback' => $runner->rollback((int) ($argument ?? 1)),
    default => displayHelp(),
};

function displayHelp(): void
{
    echo <<<'HELP'
Collective Commerce CLI

Commands:
  migrate              Run pending migrations
  rollback [steps]     Rollback last migration (or N steps)
  help                 Display this help message

Examples:
  php cli.php migrate
  php cli.php rollback
  php cli.php rollback 2

HELP;
}
