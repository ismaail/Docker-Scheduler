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
    private bool $running = true;

    public function listen(ContainerEventHandler $handler): void
    {
        logger()->info('Listening for Docker container events...');

        while ($this->running) {
            try {
                $this->openStream($handler);
            } catch (\Throwable $e) {
                if (! $this->running) {
                    // clean shutdown — don't retry
                    break;
                }

                logger()->info('[event-listener] Stream dropped: ' . $e->getMessage());
                logger()->info('[event-listener] Reconnecting in 3 seconds...');

                sleep(3);
            }
        }

        logger()->info('[event-listener] Stopped.');
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function openStream(ContainerEventHandler $handler): void
    {
        $docker = $this->createStreamingClient();

        /** @var EventStream $stream */
        $stream = $docker->systemEvents([
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

    private function createStreamingClient(): Docker
    {
        $client = DockerClientFactory::create([
            'remote_socket' => 'unix:///var/run/docker.sock',
            'ssl' => false,
            'timeout' => PHP_INT_MAX,
        ]);

        return Docker::create($client);
    }

    private function onStart(ContainerEventHandler $handler, string $containerId, string $name): void
    {
        logger()->info("[event] Container started: $name (" . substr($containerId, 0, 12) . ')');
        $handler->onContainerStart($containerId);
    }

    private function onStop(ContainerEventHandler $handler, string $containerId, string $name): void
    {
        logger()->info("[event] Container stopped: $name (" . substr($containerId, 0, 12) . ')');
        $handler->onContainerStop($containerId);
    }
}
