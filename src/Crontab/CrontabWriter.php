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

        echo '[crontab] Written ' . count($jobs) . " job(s) to {$this->crontabPath}" . PHP_EOL;
    }

    public function add(Job $job): void
    {
        $lines = $this->readLines();
        $marker = $this->marker($job);
        $cronEntry = $this->cronEntry($job);

        // Signature already exists — job is 100% identical, skip
        if (in_array($marker, $lines, true)) {
            echo "[crontab] Job {$job} unchanged, skipping" . PHP_EOL;

            return;
        }

        // Look for an existing entry for this container+job by scanning
        // cron entry lines that reference this containerID and jobName
        $oldMarker = $this->findExistingMarker($lines, $job);

        if (null !== $oldMarker) {
            // Update in place — replace old marker and cron entry
            $updated = [];
            $skip = false;

            foreach ($lines as $line) {
                if ($line === $oldMarker) {
                    // Replace old marker + cron entry with new ones
                    $updated[] = $marker;
                    $updated[] = $cronEntry;
                    $skip = true; // skip the old cron entry on next iteration

                    continue;
                }

                if ($skip) {
                    $skip = false;

                    continue;
                }

                $updated[] = $line;
            }

            file_put_contents(
                $this->crontabPath,
                implode(PHP_EOL, $updated) . PHP_EOL,
            );

            echo "[crontab] Updated job {$job}" . PHP_EOL;

            return;
        }

        // No existing entry — append
        file_put_contents(
            $this->crontabPath,
            $this->formatEntry($job) . PHP_EOL,
            FILE_APPEND,
        );

        echo "[crontab] Added job {$job}" . PHP_EOL;
    }

    /**
     * Remove all crontab entries for a given containerID.
     * Used when a container stops or dies.
     *
     * Scans cron entry lines (not markers) for the containerID,
     * then removes both the marker and the cron entry.
     */
    public function remove(string $containerId): void
    {
        $lines = $this->readLines();
        $filtered = [];
        $skip = false;
        $removed = 0;

        foreach ($lines as $line) {
            // Marker line — check if the next cron entry belongs to this container
            if (str_starts_with($line, self::MARKER_PREFIX)) {
                $skip = false; // reset
                // Peek at next line to see if it references this containerID
                $nextIndex = array_search($line, $lines) + 1;
                $nextLine = $lines[$nextIndex] ?? '';

                if (str_contains($nextLine, "docker exec {$containerId}")) {
                    $skip = true; // skip this marker and the next cron entry
                    $removed++;

                    continue;
                }
            }

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

        echo "[crontab] Removed {$removed} job(s) for container " . substr($containerId, 0, 12) . PHP_EOL;
    }

    /**
     * Check if a job with this exact signature exists in the crontab.
     */
    public function has(Job $job): bool
    {
        return in_array($this->marker($job), $this->readLines(), true);
    }

    /**
     * Format a Job as two crontab lines:
     *   # job:<signature>
     *   <schedule> docker exec <id> <command>
     */
    private function formatEntry(Job $job): string
    {
        return $this->marker($job) . PHP_EOL . $this->cronEntry($job);
    }

    /**
     * The marker comment line using the job's signature.
     * e.g. "# job:a3f1c2d4e5b6..."
     */
    private function marker(Job $job): string
    {
        return self::MARKER_PREFIX . $job->signature();
    }

    /**
     * The actual cron entry line.
     * e.g. "* * * * * docker exec abc123 php artisan schedule:run"
     */
    private function cronEntry(Job $job): string
    {
        return "{$job->schedule} docker exec {$job->containerId} {$job->command}";
    }

    /**
     * Find the marker line for an existing entry that matches this
     * container+job combination (regardless of signature).
     *
     * Looks for cron entry lines containing "docker exec <containerId>"
     * where the marker above contains a job signature — indicating the
     * same job exists but with a different signature (i.e. it changed).
     */
    private function findExistingMarker(array $lines, Job $job): ?string
    {
        $cronEntry = "docker exec {$job->containerId} {$job->command}";

        for ($i = 1, $iMax = count($lines); $i < $iMax; $i++) {
            $line = $lines[$i];

            // Match cron entry lines for this container with this job command
            if (! str_contains($line, "docker exec {$job->containerId}")) {
                continue;
            }

            // Check the marker above it is a job signature marker
            $markerAbove = $lines[$i - 1] ?? '';
            if (str_starts_with($markerAbove, self::MARKER_PREFIX)) {
                return $markerAbove; // found the old marker
            }
        }

        return null;
    }

    /**
     * Read the crontab file as an array of lines.
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
