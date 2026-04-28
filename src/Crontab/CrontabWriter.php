<?php

declare(strict_types=1);

namespace App\Crontab;

use App\Scheduler\Job;

class CrontabWriter
{
    public function __construct(
        private readonly string $crontabPath = '/etc/crontab',
    ) {}

    /**
     * Write multiple jobs at once — used on initial startup.
     * Overwrites the entire crontab file.
     *
     * @param Job[] $jobs
     */
    public function writeAll(array $jobs): void
    {
        $lines = [];

        foreach ($jobs as $job) {
            $lines[] = $this->formatEntry($job);
        }

        file_put_contents(
            $this->crontabPath,
            implode(PHP_EOL, $lines) . PHP_EOL,
        );

        echo '[crontab] Written ' . count($jobs) . " job(s) to $this->crontabPath" . PHP_EOL;
    }

    /**
     * Append a single job entry to the crontab.
     * Used when a new container starts.
     */
    public function add(Job $job): void
    {
        if ($this->has($job->containerId, $job->jobName)) {
            echo "[crontab] Job $job already exists, skipping" . PHP_EOL;

            return;
        }

        file_put_contents(
            $this->crontabPath,
            $this->formatEntry($job) . PHP_EOL,
            FILE_APPEND,
        );

        echo "[crontab] Added job $job" . PHP_EOL;
    }

    /**
     * Remove all crontab entries for a given containerID.
     * Used when a container stops or dies.
     */
    public function remove(string $containerId): void
    {
        $lines = $this->readLines();
        $marker = $this->markerPrefix($containerId);
        $filtered = [];
        $skip = false;
        $removed = 0;

        foreach ($lines as $line) {
            // If this line is a marker for our container — skip it and the next line
            if (str_starts_with($line, $marker)) {
                $skip = true;
                $removed++;

                continue;
            }

            // Skip the cron entry line that follows the marker
            if ($skip) {
                $skip = false;

                continue;
            }

            $filtered[] = $line;
        }

        if (0 === $removed) {
            echo '[crontab] No entries found for container ' . substr($containerId, 0, 12) . PHP_EOL;

            return;
        }

        file_put_contents(
            $this->crontabPath,
            implode(PHP_EOL, $filtered) . PHP_EOL,
        );

        echo "[crontab] Removed $removed job(s) for container " . substr($containerId, 0, 12) . PHP_EOL;
    }

    /**
     * Check if a specific job already exists in the crontab.
     */
    public function has(string $containerId, string $jobName): bool
    {
        $marker = $this->marker($containerId, $jobName);

        /*foreach ($this->readLines() as $line) {
            if ($line === $marker) {
                return true;
            }
        }*/
        return array_any($this->readLines(), fn ($line) => $line === $marker);

    }

    /**
     * Format a Job as two crontab lines:
     *
     * container:`<id>` job:`<name>`
     *
     * `<schedule>`  docker exec `<id> <command>`
     */
    private function formatEntry(Job $job): string
    {
        return $this->marker($job->containerId, $job->jobName) . PHP_EOL
            . "$job->schedule docker exec $job->containerId $job->command";
    }

    /**
     * The comment marker line for a specific job.
     * e.g. "# container:a1b2c3d4e5f6 job:laravel"
     */
    private function marker(string $containerId, string $jobName): string
    {
        return "# container:$containerId job:$jobName";
    }

    /**
     * The marker prefix used to match ALL jobs for a container.
     * e.g. "# container:a1b2c3d4e5f6"
     */
    private function markerPrefix(string $containerId): string
    {
        return "# container: $containerId";
    }

    /**
     * Read the crontab file as an array of lines.
     * Returns empty array if file doesn't exist or is empty.
     *
     * @return string[]
     */
    private function readLines(): array
    {
        if (! file_exists($this->crontabPath)) {
            return [];
        }

        $content = file_get_contents($this->crontabPath);
        if (empty(trim($content))) {
            return [];
        }

        return explode(PHP_EOL, rtrim($content));
    }
}
