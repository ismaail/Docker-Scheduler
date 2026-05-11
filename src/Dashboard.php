<?php

declare(strict_types=1);

namespace App;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

class Dashboard
{
    public function __construct(
        private readonly string $crontabPath = '/etc/crontab',
        private readonly int $port = 8080,
    ) {}

    public function start(): void
    {
        $server = new Server('0.0.0.0', $this->port);

        $server->on('request', function (Request $request, Response $response) {
            $jobs = $this->readCrontab();
            $count = count($jobs);
            $updated = date('Y-m-d H:i:s');
            $rows = $this->buildRows($jobs);

            $response->header('Content-Type', 'text/html; charset=utf-8');
            $response->header('Refresh', '5');
            $response->end($this->html($count, $updated, $rows));
        });

        logger()->info("Dashboard running on http://0.0.0.0:$this->port");

        $server->start();
    }

    /**
     * @param array<int, array{container: string, schedule: string, command: string, signature: string}> $jobs
     */
    private function buildRows(array $jobs): string
    {
        if (empty($jobs)) {
            return '<tr><td colspan="4" class="empty">No jobs registered yet.</td></tr>';
        }

        return implode('', array_map(fn (array $job) => '<tr>'
            . '<td>' . htmlspecialchars(substr($job['container'], 0, 12)) . '</td>'
            . '<td>' . htmlspecialchars($job['schedule']) . '</td>'
            . '<td><code>' . htmlspecialchars($job['command']) . '</code></td>'
            . '<td><code>' . htmlspecialchars(substr($job['signature'], 0, 16)) . '...</code></td>'
            . '</tr>', $jobs));
    }

    /**
     * @return array<int, array{container: string, schedule: string, command: string, signature: string}>
     */
    private function readCrontab(): array
    {
        if (! file_exists($this->crontabPath)) {
            return [];
        }

        $content = file_get_contents($this->crontabPath);
        if (empty(trim($content))) {
            return [];
        }

        $lines = array_values(array_filter(explode(PHP_EOL, rtrim($content))));
        $jobs = [];

        foreach ($lines as $i => $iValue) {
            if (! str_starts_with($iValue, '# job:')) {
                continue;
            }

            $signature = substr($iValue, strlen('# job:'));
            $cronLine = $lines[$i + 1] ?? '';

            if (! preg_match('/^(.+?)\s+docker exec\s+(\S+)\s+(.+)$/', $cronLine, $m)) {
                continue;
            }

            $jobs[] = [
                'container' => $m[2],
                'schedule' => $m[1],
                'command' => $m[3],
                'signature' => $signature,
            ];

            //$i++;
        }

        return $jobs;
    }

    private function html(int $count, string $updated, string $rows): string
    {
        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <title>Scheduler Dashboard</title>
                <style>
                    * { box-sizing: border-box; margin: 0; padding: 0; }
                    body { font-family: -apple-system, sans-serif; background: #0f172a; color: #e2e8f0; padding: 2rem; }
                    h1 { font-size: 1.5rem; margin-bottom: 0.25rem; color: #f8fafc; }
                    .meta { font-size: 0.85rem; color: #64748b; margin-bottom: 2rem; }
                    .meta span { color: #38bdf8; }
                    .badge { background: #1e293b; border: 1px solid #334155; border-radius: 9999px; padding: 0.2rem 0.75rem; font-size: 0.8rem; margin-left: 0.5rem; }
                    table { width: 100%; border-collapse: collapse; background: #1e293b; border-radius: 0.5rem; overflow: hidden; }
                    th { text-align: left; padding: 0.75rem 1rem; font-size: 0.75rem; text-transform: uppercase; color: #64748b; border-bottom: 1px solid #334155; }
                    td { padding: 0.75rem 1rem; font-size: 0.875rem; border-bottom: 1px solid #0f172a; }
                    tr:last-child td { border-bottom: none; }
                    tr:hover td { background: #263144; }
                    code { font-family: monospace; font-size: 0.8rem; color: #7dd3fc; }
                    .empty { text-align: center; padding: 2rem; color: #475569; }
                </style>
            </head>
            <body>
                <h1>Scheduler Dashboard <span class="badge">$count job(s)</span></h1>
                <p class="meta">Auto-refreshes every 5s &nbsp;·&nbsp; Last updated: <span>$updated</span></p>
                <table>
                    <thead><tr><th>Container</th><th>Schedule</th><th>Command</th><th>Signature</th></tr></thead>
                    <tbody>$rows</tbody>
                </table>
            </body>
            </html>
            HTML;
    }
}
