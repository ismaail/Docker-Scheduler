<?php

declare(strict_types=1);

namespace App\Scheduler;

use Cron\CronExpression;
use InvalidArgumentException;

class ScheduleParser
{
    private const array ALIASES = [
        '@hourly',
        '@daily',
        '@weekly',
        '@monthly',
        '@yearly',
        '@annually',
        '@midnight',
    ];

    /**
     * @throws InvalidArgumentException if the expression is invalid or unsupported
     */
    public function parse(string $schedule): string
    {
        $schedule = trim($schedule);

        if (empty($schedule)) {
            throw new InvalidArgumentException('Schedule expression cannot be empty');
        }

        // Pass through known aliases
        if (in_array(strtolower($schedule), self::ALIASES, strict: true)) {
            return strtolower($schedule);
        }

        // Convert @every format
        if (str_starts_with($schedule, '@every ')) {
            return $this->parseEvery($schedule);
        }

        // Validate and pass through standard 5-field cron
        if ($this->isValidCron($schedule)) {
            return $schedule;
        }

        throw new InvalidArgumentException(
            "Invalid or unsupported schedule expression: \"$schedule}\". " .
            'Use standard cron format (e.g. "* * * * *"), ' .
            'a supported alias (@hourly, @daily, @weekly, @monthly, @yearly), ' .
            'or @every with a duration (e.g. "@every 5m", "@every 2h").'
        );
    }

    /**
     * Parse "@every <duration>" expressions.
     *
     * Supported units: m (minutes), h (hours), d (days)
     * Minimum interval: 1 minute
     */
    private function parseEvery(string $schedule): string
    {
        // Extract the duration part: "@every 5m" → "5m"
        $duration = trim(substr($schedule, strlen('@every ')));

        if (empty($duration)) {
            throw new InvalidArgumentException("Missing duration in \"$schedule\"");
        }

        // Match number + unit: "5m", "2h", "1d"
        if (! preg_match('/^(\d+)([smhd])$/', $duration, $matches)) {
            throw new InvalidArgumentException(
                "Invalid duration \"$duration\" in \"$schedule\". " .
                'Supported units: m (minutes), h (hours), d (days). ' .
                'Example: "@every 5m", "@every 2h", "@every 1d"'
            );
        }

        $value = (int)$matches[1];
        $unit = $matches[2];

        if ($value <= 0) {
            throw new InvalidArgumentException("Duration value must be greater than 0 in \"$schedule\"");
        }

        return match ($unit) {
            's' => throw new InvalidArgumentException(
                "Seconds are not supported in \"$schedule\". Minimum interval is 1 minute."
            ),
            'm' => $this->everyMinutes($value),
            'h' => $this->everyHours($value),
            'd' => $this->everyDays($value),
        };
    }

    private function everyMinutes(int $minutes): string
    {
        if (1 === $minutes) {
            return '* * * * *';
        }

        if ($minutes > 59) {
            throw new InvalidArgumentException(
                "Minute interval $minutes exceeds 59. Use hours instead (e.g. \"@every 1h\")."
            );
        }

        return "*/$minutes * * * *";
    }

    private function everyHours(int $hours): string
    {
        if (1 === $hours) {
            return '0 * * * *';
        }

        if ($hours > 23) {
            throw new InvalidArgumentException(
                "Hour interval $hours exceeds 23. Use days instead (e.g. \"@every 1d\")."
            );
        }

        return "0 */$hours * * *";
    }

    private function everyDays(int $days): string
    {
        if (1 === $days) {
            return '0 0 * * *';
        }

        if ($days > 31) {
            throw new InvalidArgumentException(
                "Day interval $days exceeds 31."
            );
        }

        return "0 0 */$days * *";
    }

    private function isValidCron(string $expression): bool
    {
        try {
            new CronExpression($expression);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
