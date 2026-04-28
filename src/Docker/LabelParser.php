<?php

declare(strict_types=1);

namespace App\Docker;

use App\Scheduler\Job;

/**
 * Parses container labels into Job objects.
 *
 * Scans all labels looking for the "acme.<n>.schedule" pattern,
 * extracts the dynamic job name <n>, then looks up the matching command.
 */
class LabelParser
{
    /**
     * Parse all jobs defined in a container's labels.
     *
     * How it works:
     *  1. Loop over all labels looking for "acme.*.schedule"
     *  2. Extract <n> from between "acme." and ".schedule"
     *  3. Look up the matching "acme.<n>.command" label
     *
     * A single container can define multiple jobs:
     *   acme.enabled           = "true"
     *   acme.laravel.schedule  = "* * * * *"
     *   acme.laravel.command   = "php artisan schedule:run"
     *   acme.backup.schedule   = "0 2 * * *"
     *   acme.backup.command    = "php artisan backup:run"
     *
     * @param string[] $labels
     * @return Job[]
     */
    public function parse(string $containerId, string $containerName, iterable $labels): array
    {
        $jobs = [];

        foreach ($labels as $labelKey => $labelValue) {
            // Only interested in "acme.*.schedule" labels
            if (! str_starts_with($labelKey, Job::LABEL_PREFIX)) {
                continue;
            }
            if (! str_ends_with($labelKey, Job::LABEL_SUFFIX_SCHEDULE)) {
                continue;
            }

            // Extract the dynamic job name
            // "acme.laravel.schedule" → strip "acme." → "laravel.schedule" → strip ".schedule" → "laravel"
            $withoutPrefix = substr($labelKey, strlen(Job::LABEL_PREFIX));
            $jobName = substr($withoutPrefix, 0, -strlen(Job::LABEL_SUFFIX_SCHEDULE));

            if (empty($jobName)) {
                echo "Container {$containerName}: empty job name in label \"{$labelKey}\", skipping" . PHP_EOL;

                continue;
            }

            $schedule = trim($labelValue);
            if (empty($schedule)) {
                echo "Container {$containerName}, job \"{$jobName}\": empty schedule, skipping" . PHP_EOL;

                continue;
            }

            // Look up the matching command label: acme.<n>.command
            $commandKey = Job::LABEL_PREFIX . $jobName . Job::LABEL_SUFFIX_COMMAND;
            $command = trim($labels[$commandKey] ?? '');

            if (empty($command)) {
                echo "Container {$containerName}, job \"{$jobName}\": missing label \"{$commandKey}\", skipping" . PHP_EOL;

                continue;
            }

            $jobs[] = new Job(
                containerId: $containerId,
                containerName: $containerName,
                jobName: $jobName,
                schedule: $schedule,
                command: $command,
            );
        }

        return $jobs;
    }
}
