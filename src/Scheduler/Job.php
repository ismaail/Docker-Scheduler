<?php

declare(strict_types=1);

namespace App\Scheduler;

/**
 * Job holds the parsed scheduling info extracted from a container's labels.
 *
 * Expected label format on each container:
 *   acme.enabled       = "true"
 *   acme.<n>.schedule  = "@every 1m"
 *   acme.<n>.command   = "php artisan schedule:run"
 */
class Job
{
    public const string LABEL_ENABLED = 'acme.enabled';

    public const string LABEL_PREFIX = 'acme.';

    public const string LABEL_SUFFIX_SCHEDULE = '.schedule';

    public const string LABEL_SUFFIX_COMMAND = '.command';

    public function __construct(
        public readonly string $containerId,
        public readonly string $containerName,
        public readonly string $jobName, // dynamic part, e.g. "laravel" in acme.laravel.*
        public readonly string $schedule,
        public readonly string $command,
    ) {}

    public function signature(): string
    {
        return hash('sha256', implode('|', [
            $this->containerId,
            $this->containerName,
            $this->jobName,
            $this->command,
        ]));
    }

    public function __toString(): string
    {
        return sprintf('[%s/%s]', $this->containerName, $this->jobName);
    }
}
