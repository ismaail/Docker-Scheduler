<?php

declare(strict_types=1);

namespace App\Docker\Contracts;

/**
 * Interface for handling Docker container lifecycle events.
 *
 * The scheduler Engine implements this interface so it can be passed
 * directly to EventListener::listen() — same pattern as Go's
 * ContainerEventHandler interface in events.go.
 */
interface ContainerEventHandler
{
    /**
     * Called when a container starts.
     * Should inspect the container's labels and register any scheduler jobs.
     */
    public function onContainerStart(string $containerId): void;

    /**
     * Called when a container stops or dies.
     * Should remove all cron jobs associated with this container.
     */
    public function onContainerStop(string $containerId): void;
}
