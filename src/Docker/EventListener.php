<?php

declare(strict_types=1);

namespace App\Docker;

use App\Docker\Contracts\ContainerEventHandler;
use Docker\API\Model\EventMessage;
use Docker\Docker;
use Docker\DockerClientFactory;
use Docker\Stream\EventStream;

class EventListener
{
    private Docker $docker;

    private bool $running = true;

    public function __construct()
    {
        $client = DockerClientFactory::create([
            'remote_socket' => 'unix:///var/run/docker.sock',
            'ssl' => false,
        ]);

        $this->docker = Docker::create($client);
    }

    public function listen(ContainerEventHandler $handler): void
    {
        echo 'Listening for Docker container events...' . PHP_EOL;

        while ($this->running) {
            try {
                $this->openStream($handler);
            } catch (\Throwable $e) {
                if (! $this->running) {
                    // clean shutdown — don't retry
                    break;
                }

                echo '[event-listener] Stream dropped: ' . $e->getMessage() . PHP_EOL;
                echo '[event-listener] Reconnecting in 3 seconds...' . PHP_EOL;

                sleep(3);
            }
        }

        echo '[event-listener] Stopped.' . PHP_EOL;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function openStream(ContainerEventHandler $handler): void
    {
        /** @var EventStream $stream */
        $stream = $this->docker->systemEvents([
            'filters' => json_encode([
                'type' => ['container'],
                'event' => ['start', 'stop', 'die'],
            ]),
        ]);

        $stream->onFrame(function (EventMessage $event) use ($handler) {
            $action = $event->getAction();
            $containerId = $event->getActor()?->getID() ?? '';
            $attributes = $event->getActor()?->getAttributes() ?? [];
            $name = $attributes['name'] ?? substr($containerId, 0, 12);

            match ($action) {
                'start' => $this->onStart($handler, $containerId, $name),
                'stop', 'die' => $this->onStop($handler, $containerId, $name),
                default => null,
            };
        });

        $stream->wait();
    }

    private function onStart(ContainerEventHandler $handler, string $containerId, string $name): void
    {
        echo "[event] Container started: $name (" . substr($containerId, 0, 12) . ')' . PHP_EOL;
        $handler->onContainerStart($containerId);
    }

    private function onStop(ContainerEventHandler $handler, string $containerId, string $name): void
    {
        echo "[event] Container stopped: $name (" . substr($containerId, 0, 12) . ')' . PHP_EOL;
        $handler->onContainerStop($containerId);
    }
}
