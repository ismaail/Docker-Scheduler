<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';

use App\Docker\ContainerRepository;
use App\Docker\LabelParser;
use Docker\Docker;

$docker = Docker::create();
$repository = new ContainerRepository($docker, new LabelParser());

$jobs = $repository->discoverJobs();

if (empty($jobs)) {
    echo PHP_EOL . 'No jobs found. To enable a container add these labels:' . PHP_EOL;
    echo '  acme.enabled=true' . PHP_EOL;
    echo '  acme.<n>.schedule=* * * * *' . PHP_EOL;
    echo '  acme.<n>.command=php artisan schedule:run' . PHP_EOL;

    exit(0);
}

foreach ($jobs as $job) {
    echo PHP_EOL;
    echo "  Container : {$job->containerName}" . PHP_EOL;
    echo '  ID        : ' . substr($job->containerId, 0, 12) . PHP_EOL;
    echo "  Job       : {$job->jobName}" . PHP_EOL;
    echo "  Schedule  : {$job->schedule}" . PHP_EOL;
    echo "  Command   : {$job->command}" . PHP_EOL;
}
