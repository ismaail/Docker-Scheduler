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

    /**
     * Add a job entry to the crontab.
     *
     * Signature is used to detect the state:
     *   - Signature found     → identical job, skip
     *   - Signature not found → append as new entry
     *
     * If the job's labels changed (command, schedule, etc.), the container
     * will have stopped and restarted — triggering remove() then add(),
     * so update-in-place is not needed here.
     */
    public function add(Job $job): void
    {
        if ($this->has($job)) {
            echo "[crontab] Job {$job} unchanged, skipping" . PHP_EOL;

            return;
        }

        file_put_contents(
            filename: $this->crontabPath,
            data: $this->formatEntry($job) . PHP_EOL,
            flags: FILE_APPEND,
        );

        echo "[crontab] Added job {$job}" . PHP_EOL;
    }

    /**
     * Remove all crontab entries for a given containerID.
     * Used when a container stops or dies.
     *
     * Scans marker lines and peeks at the following cron entry line
     * to check if it references this containerID.
     */
    public function remove(string $containerId): void
    {
        $lines = $this->readLines();
        $filtered = [];
        $removed = 0;
        $i = 0;

        while ($i < count($lines)) {
            $line = $lines[$i];

            // Check if this is a marker line whose cron entry belongs to this container
            if (
                str_starts_with($line, self::MARKER_PREFIX) &&
                str_contains($lines[$i + 1] ?? '', "docker exec {$containerId} ")
            ) {
                $removed++;
                $i += 2; // skip marker + cron entry

                continue;
            }

            $filtered[] = $line;
            $i++;
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
     * Check if a job with this exact signature exists in the crontab.
     */
    public function has(Job $job): bool
    {
        return in_array($this->marker($job), $this->readLines(), strict: true);
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
     * e.g. `# job:a3f1c2d4e5b6...`
     */
    private function marker(Job $job): string
    {
        return self::MARKER_PREFIX . $job->signature();
    }

    /**
     * The actual cron entry line.
     * e.g. `* * * * * docker exec abc123 php artisan schedule:run`
     */
    private function cronEntry(Job $job): string
    {
        return "$job->schedule docker exec $job->containerId $job->command";
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
