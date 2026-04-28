<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';

use App\Docker\Contracts\ContainerEventHandler;
use App\Docker\EventListener;
use Swoole\Process;
use Swoole\Runtime;

Runtime::setHookFlags(0);

$listener = new EventListener();

$listener->listen(new class implements ContainerEventHandler
{
    public function onContainerStart(string $containerId): void
    {
        echo "START $containerId\n";
    }

    public function onContainerStop(string $containerId): void
    {
        echo "STOP $containerId\n";
    }
});

Process::signal(SIGINT, function () {
    echo PHP_EOL . 'Stopping...' . PHP_EOL;
    exit(0);
});
