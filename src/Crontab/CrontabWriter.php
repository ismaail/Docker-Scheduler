<?php

declare(strict_types=1);

namespace App\Crontab;

use App\Scheduler\Job;

class CrontabWriter
{
    private const string MARKER_PREFIX = '# job:';

    public function __construct(
        private readonly string $crontabPath = '/etc/crontab',
    ) {}

    /**
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

        logger()->info('[crontab] Written ' . count($jobs) . " job(s) to $this->crontabPath");
    }

    /**
     * Add or update a job entry in the crontab.
     *
     * Uses signature (containerId + containerName + jobName + command) to detect state:
     *   - Signature found + same cron entry   → identical, skip
     *   - Signature found + different entry   → schedule changed, update in place
     *   - Signature not found                 → new job, append
     */
    public function add(Job $job): void
    {
        $lines = $this->readLines();
        $marker = $this->marker($job);
        $cronEntry = $this->cronEntry($job);

        foreach ($lines as $i => $iValue) {
            if ($iValue !== $marker) {
                continue;
            }

            if (($lines[$i + 1] ?? '') === $cronEntry) {
                logger()->info("[crontab] Job $job unchanged, skipping");

                return;
            }

            // Same identity, different schedule — update cron entry line in place
            $lines[$i + 1] = $cronEntry;
            file_put_contents(
                $this->crontabPath,
                implode(PHP_EOL, $lines) . PHP_EOL,
            );
            logger()->info("[crontab] Updated schedule for job $job");

            return;
        }

        file_put_contents(
            filename: $this->crontabPath,
            data: $this->formatEntry($job) . PHP_EOL,
            flags: FILE_APPEND,
        );

        logger()->info("[crontab] Added job $job");
    }

    /**
     * Remove all crontab entries for a given containerID.
     * Used when a container stops or dies.
     */
    public function remove(string $containerId): void
    {
        $lines = $this->readLines();
        $filtered = [];
        $removed = 0;
        $i = 0;

        while ($i < count($lines)) {
            $line = $lines[$i];

            if (
                str_starts_with($line, self::MARKER_PREFIX) &&
                str_contains($lines[$i + 1] ?? '', "docker exec $containerId ")
            ) {
                $removed++;
                $i += 2;

                continue;
            }

            $filtered[] = $line;
            $i++;
        }

        if (0 === $removed) {
            logger()->info('[crontab] No entries found for container ' . substr($containerId, 0, 12));

            return;
        }

        file_put_contents(
            $this->crontabPath,
            implode(PHP_EOL, $filtered) . PHP_EOL,
        );

        logger()->info("[crontab] Removed $removed job(s) for container " . substr($containerId, 0, 12));
    }

    /**
     * Check if a job with this exact signature exists in the crontab.
     */
    public function has(Job $job): bool
    {
        return in_array($this->marker($job), $this->readLines(), strict: true);
    }

    private function formatEntry(Job $job): string
    {
        return $this->marker($job) . PHP_EOL . $this->cronEntry($job);
    }

    private function marker(Job $job): string
    {
        return self::MARKER_PREFIX . $job->signature();
    }

    private function cronEntry(Job $job): string
    {
        return "$job->schedule docker exec $job->containerId $job->command";
    }

    /**
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
