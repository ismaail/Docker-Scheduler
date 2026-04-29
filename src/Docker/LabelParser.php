<?php

declare(strict_types=1);

namespace App\Docker;

use App\Scheduler\Job;
use App\Scheduler\ScheduleParser;
use InvalidArgumentException;

class LabelParser
{
    public function __construct(
        private readonly ScheduleParser $scheduleParser = new ScheduleParser(),
    ) {}

    public function parse(string $containerId, string $containerName, iterable $labels): array
    {
        $jobs = [];

        foreach ($labels as $labelKey => $labelValue) {
            if (! str_starts_with($labelKey, Job::LABEL_PREFIX)) {
                continue;
            }
            if (! str_ends_with($labelKey, Job::LABEL_SUFFIX_SCHEDULE)) {
                continue;
            }

            $withoutPrefix = substr($labelKey, strlen(Job::LABEL_PREFIX));
            $jobName = substr($withoutPrefix, 0, -strlen(Job::LABEL_SUFFIX_SCHEDULE));

            if (empty($jobName)) {
                logger()->info("Container $containerName: empty job name in label \"$labelKey\", skipping");

                continue;
            }

            $rawSchedule = trim($labelValue);
            if (empty($rawSchedule)) {
                logger()->info("Container $containerName, job \"$jobName\": empty schedule, skipping");

                continue;
            }

            // Parse and normalize the schedule — converts "@every 5m" → "*/5 * * * *"
            // Throws InvalidArgumentException if the expression is invalid or unsupported
            try {
                $schedule = $this->scheduleParser->parse($rawSchedule);
            } catch (InvalidArgumentException $e) {
                logger()->info("Container $containerName, job \"$jobName\": {$e->getMessage()}, skipping");

                continue;
            }

            $commandKey = Job::LABEL_PREFIX . $jobName . Job::LABEL_SUFFIX_COMMAND;
            $command = trim($labels[$commandKey] ?? '');

            if (empty($command)) {
                logger()->info("Container $containerName, job \"$jobName\": missing label \"$commandKey\", skipping");

                continue;
            }

            $jobs[] = new Job(
                containerId: $containerId,
                containerName: $containerName,
                jobName: $jobName,
                schedule: $schedule, // normalized, not raw label value
                command: $command,
            );
        }

        return $jobs;
    }
}
