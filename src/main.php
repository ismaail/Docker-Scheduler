<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';

use Docker\Docker;

$docker = Docker::create();
$containers = $docker->containerList();

dump($containers);
