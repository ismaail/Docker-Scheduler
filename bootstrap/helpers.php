<?php

declare(strict_types=1);

use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\SocketHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

if (! function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? $default;
    }
}

if (! function_exists('logger')) {
    function logger(): Logger
    {
        static $logger = null;

        if (null !== $logger) {
            return $logger;
        }

        $logger = new Logger('app');

        $level = Logger::toMonologLevel(env('LOG_LEVEL', 'debug'));
        $channel = env('MONOLOG_DEFAULT_CHANNEL', 'stdout');

        if ('socket' === $channel) {
            $socketUrl = env('LOG_SOCKET_URL');

            if (! $socketUrl) {
                throw new RuntimeException('LOG_SOCKET_URL is not defined for socket channel');
            }

            $handler = new SocketHandler($socketUrl, $level);
            $handler->setFormatter(new JsonFormatter());
        } else {
            $env = env('APP_ENV');
            $handler = 'testing' === $env
                ? new NullHandler()
                : new StreamHandler('php://stdout', $level);

            if ('testing' !== $env) {
                $handler->setFormatter(new LineFormatter(
                    format: "[%datetime%] %level_name%: %message% %context% %extra%\n",
                    dateFormat: 'Y-m-d H:i:s',
                    allowInlineLineBreaks: true,
                    ignoreEmptyContextAndExtra: true,
                ));
            }
        }

        $logger->pushHandler($handler);

        return $logger;
    }
}
