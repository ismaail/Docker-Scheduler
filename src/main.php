<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';

use App\Crontab\CrontabWriter;
use App\Dashboard;
use App\Docker\ContainerRepository;
use App\Docker\Contracts\ContainerEventHandler;
use App\Docker\EventListener;
use App\Docker\LabelParser;
use Docker\Docker;
use Docker\DockerClientFactory;
use Swoole\Process;

$client = DockerClientFactory::create([
    'remote_socket' => 'unix:///var/run/docker.sock',
    'ssl' => false,
]);

$docker = Docker::create($client);
$repository = new ContainerRepository($docker, new LabelParser());
$crontab = new CrontabWriter();

/*
|--------------------------------------------------------------------------
| Event Handler
|--------------------------------------------------------------------------
|
*/

$handler = new class($repository, $crontab) implements ContainerEventHandler
{
    public function __construct(
        private readonly ContainerRepository $repository,
        private readonly CrontabWriter $crontab,
    ) {}

    public function onContainerStart(string $containerId): void
    {
        $jobs = $this->repository->discoverJobsByContainerId($containerId);

        if (empty($jobs)) {
            return; // container has no scheduler labels — not our concern
        }

        foreach ($jobs as $job) {
            $this->crontab->add($job);
        }
    }

    public function onContainerStop(string $containerId): void
    {
        $this->crontab->remove($containerId);
    }
};

/*
|--------------------------------------------------------------------------
| Initial Scan
|--------------------------------------------------------------------------
|
*/

$jobs = $repository->discoverJobs();

if (! empty($jobs)) {
    $crontab->writeAll($jobs);
}

/*
|--------------------------------------------------------------------------
| Event Listener + Dashboard
|--------------------------------------------------------------------------
|
*/

// Run event listener in a separate process
$process = new Process(function () use ($handler) {
    new EventListener()->listen($handler);
});
$process->start();

new Dashboard()->start();
