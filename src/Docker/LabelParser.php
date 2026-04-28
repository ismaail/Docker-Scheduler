<?php

declare(strict_types=1);

namespace App\Docker;

use App\Scheduler\Job;
use Cron\CronExpression;

/**
 * Parses container labels into Job objects.
 *
 * Scans all labels looking for the "acme.<n>.schedule" pattern,
 * extracts the dynamic job name <n>, then looks up the matching command.
 */
class LabelParser
{
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
                echo "Container $containerName: empty job name in label \"$labelKey\", skipping" . PHP_EOL;

                continue;
            }

            $schedule = trim($labelValue);
            if (empty($schedule)) {
                echo "Container $containerName, job \"$jobName\": empty schedule, skipping" . PHP_EOL;

                continue;
            }

            // Validate the cron expression before accepting it
            if (! $this->isValidSchedule($schedule)) {
                echo "Container $containerName, job \"$jobName\": invalid schedule \"$schedule\"" . PHP_EOL;
                echo '  Supported formats: "* * * * *", "@hourly", "@daily", "@weekly", "@monthly", "@yearly"' . PHP_EOL;
                echo '  Note: "@every 1m" is not supported — use "* * * * *" instead' . PHP_EOL;

                continue;
            }

            // Look up the matching command label: acme.<n>.command
            $commandKey = Job::LABEL_PREFIX . $jobName . Job::LABEL_SUFFIX_COMMAND;
            $command = trim($labels[$commandKey] ?? '');

            if (empty($command)) {
                echo "Container $containerName, job \"$jobName\": missing label \"$commandKey\", skipping" . PHP_EOL;

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

    private function isValidSchedule(string $schedule): bool
    {
        try {
            new CronExpression($schedule);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
