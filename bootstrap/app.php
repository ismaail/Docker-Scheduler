<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Init Sentry
Sentry\init([
    'dsn' => (string)env('SENTRY_DSN'),
    // Optionally, enable performance tracing
    'traces_sample_rate' => 1.0,
]);
