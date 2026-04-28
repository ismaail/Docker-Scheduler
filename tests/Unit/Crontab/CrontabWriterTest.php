<?php

declare(strict_types=1);

use App\Crontab\CrontabWriter;
use App\Scheduler\Job;

/**
 * Create a temporary crontab file for each test.
 * Using sys_get_temp_dir() avoids writing to /etc/crontab during tests.
 */
function makeTempCrontab(): string
{
    $path = sys_get_temp_dir() . '/crontab_test_' . uniqid('', true);
    touch($path);

    return $path;
}

function makeJob(
    string $containerId = 'abc123def456abc123def456abc123def456abc123def456abc123def456abc123',
    string $containerName = 'test_app',
    string $jobName = 'laravel',
    string $schedule = '* * * * *',
    string $command = 'php artisan schedule:run',
): Job {
    return new Job(
        containerId: $containerId,
        containerName: $containerName,
        jobName: $jobName,
        schedule: $schedule,
        command: $command,
    );
}

function crontabLines(string $path): array
{
    $content = file_get_contents($path);

    if (empty(trim($content))) {
        return [];
    }

    return explode(PHP_EOL, rtrim($content));
}

describe('add()', function () {
    it('writes signature marker and cron entry as two consecutive lines', function () {
        $path = makeTempCrontab();
        $writer = new CrontabWriter($path);
        $job = makeJob(containerId: 'aaa111', jobName: 'laravel', schedule: '* * * * *', command: 'php artisan schedule:run');

        $writer->add($job);

        $lines = crontabLines($path);
        expect($lines[0])->toBe('# job:' . $job->signature());
        expect($lines[1])->toBe('* * * * * docker exec aaa111 php artisan schedule:run');

        unlink($path);
    });

    it('appends new job after existing entries without overwriting', function () {
        $path = makeTempCrontab();
        $writer = new CrontabWriter($path);

        $job1 = makeJob(containerId: 'aaa111', jobName: 'laravel', schedule: '* * * * *', command: 'php artisan schedule:run');
        $job2 = makeJob(containerId: 'bbb222', jobName: 'backup', schedule: '0 2 * * *', command: 'php artisan backup:run');

        $writer->add($job1);
        $writer->add($job2);

        $lines = crontabLines($path);
        expect($lines[0])->toBe('# job:' . $job1->signature());
        expect($lines[1])->toBe('* * * * * docker exec aaa111 php artisan schedule:run');
        expect($lines[2])->toBe('# job:' . $job2->signature());
        expect($lines[3])->toBe('0 2 * * * docker exec bbb222 php artisan backup:run');

        unlink($path);
    });

    it('skips identical job — file content stays unchanged', function () {
        $path = makeTempCrontab();
        $writer = new CrontabWriter($path);
        $job = makeJob(containerId: 'aaa111', jobName: 'laravel', command: 'php artisan schedule:run');

        $writer->add($job);
        $contentBefore = file_get_contents($path);

        $writer->add($job);

        expect(file_get_contents($path))->toBe($contentBefore);
        expect(substr_count(file_get_contents($path), '# job:' . $job->signature()))->toBe(1);

        unlink($path);
    });

    it('updates cron entry in place when only schedule changes — same signature', function () {
        $path = makeTempCrontab();
        $writer = new CrontabWriter($path);

        $oldJob = makeJob(containerId: 'aaa111', jobName: 'laravel', schedule: '* * * * *', command: 'php artisan schedule:run');
        $newJob = makeJob(containerId: 'aaa111', jobName: 'laravel', schedule: '* 2 * * *', command: 'php artisan schedule:run');

        $writer->add($oldJob);
        $writer->add($newJob);

        $lines = crontabLines($path);
        expect($lines)->toHaveCount(2);
        expect($lines[0])->toBe('# job:' . $newJob->signature()); // same as oldJob — schedule not in signature
        expect($lines[1])->toBe('* 2 * * * docker exec aaa111 php artisan schedule:run');
        expect(file_get_contents($path))->not->toContain('* * * * * docker exec aaa111');

        unlink($path);
    });

    it('appends as new entry when command changes — different signatures coexist until container restarts', function () {
        $path = makeTempCrontab();
        $writer = new CrontabWriter($path);

        $oldJob = makeJob(containerId: 'aaa111', jobName: 'laravel', schedule: '* * * * *', command: 'php artisan schedule:run');
        $newJob = makeJob(containerId: 'aaa111', jobName: 'laravel', schedule: '* * * * *', command: 'php artisan app:test');

        $writer->add($oldJob);
        $writer->add($newJob);

        $lines = crontabLines($path);
        expect($lines)->toHaveCount(4);
        expect($lines[0])->toBe('# job:' . $oldJob->signature());
        expect($lines[1])->toBe('* * * * * docker exec aaa111 php artisan schedule:run');
        expect($lines[2])->toBe('# job:' . $newJob->signature());
        expect($lines[3])->toBe('* * * * * docker exec aaa111 php artisan app:test');

        unlink($path);
    });

    it('writes two jobs for same container as separate entries', function () {
        $path = makeTempCrontab();
        $writer = new CrontabWriter($path);

        $job1 = makeJob(containerId: 'aaa111', jobName: 'laravel', schedule: '* * * * *', command: 'php artisan schedule:run');
        $job2 = makeJob(containerId: 'aaa111', jobName: 'backup', schedule: '0 2 * * *', command: 'php artisan backup:run');

        $writer->add($job1);
        $writer->add($job2);

        $lines = crontabLines($path);
        expect($lines)->toHaveCount(4);
        expect($lines[0])->toBe('# job:' . $job1->signature());
        expect($lines[1])->toBe('* * * * * docker exec aaa111 php artisan schedule:run');
        expect($lines[2])->toBe('# job:' . $job2->signature());
        expect($lines[3])->toBe('0 2 * * * docker exec aaa111 php artisan backup:run');

        unlink($path);
    });

    it('supports @hourly schedule format', function () {
        $path = makeTempCrontab();
        $writer = new CrontabWriter($path);
        $job = makeJob(containerId: 'aaa111', jobName: 'cleanup', schedule: '@hourly', command: 'php artisan cleanup:run');

        $writer->add($job);

        $lines = crontabLines($path);
        expect($lines[0])->toBe('# job:' . $job->signature());
        expect($lines[1])->toBe('@hourly docker exec aaa111 php artisan cleanup:run');

        unlink($path);
    });
});

describe('writeAll()', function () {
    it('writes all jobs in order as consecutive line pairs', function () {
        $path = makeTempCrontab();
        $writer = new CrontabWriter($path);

        $job1 = makeJob(containerId: 'aaa111', jobName: 'laravel', schedule: '* * * * *', command: 'php artisan schedule:run');
        $job2 = makeJob(containerId: 'bbb222', jobName: 'backup', schedule: '0 2 * * *', command: 'php artisan backup:run');

        $writer->writeAll([$job1, $job2]);

        $lines = crontabLines($path);
        expect($lines[0])->toBe('# job:' . $job1->signature());
        expect($lines[1])->toBe('* * * * * docker exec aaa111 php artisan schedule:run');
        expect($lines[2])->toBe('# job:' . $job2->signature());
        expect($lines[3])->toBe('0 2 * * * docker exec bbb222 php artisan backup:run');

        unlink($path);
    });

    it('overwrites existing crontab content completely', function () {
        $path = makeTempCrontab();
        $old = makeJob(containerId: 'old111', jobName: 'old', schedule: '* * * * *', command: 'php artisan old:command');
        file_put_contents($path, '# job:' . $old->signature() . PHP_EOL . '* * * * * docker exec old111 php artisan old:command' . PHP_EOL);

        $writer = new CrontabWriter($path);
        $new = makeJob(containerId: 'new111', jobName: 'laravel', schedule: '* * * * *', command: 'php artisan schedule:run');
        $writer->writeAll([$new]);

        $lines = crontabLines($path);
        expect($lines)->toHaveCount(2);
        expect($lines[0])->toBe('# job:' . $new->signature());
        expect($lines[1])->toBe('* * * * * docker exec new111 php artisan schedule:run');
        expect(file_get_contents($path))->not->toContain('old111');

        unlink($path);
    });

    it('writes empty file when given no jobs', function () {
        $path = makeTempCrontab();
        $job = makeJob(containerId: 'old111');
        file_put_contents($path, '# job:' . $job->signature() . PHP_EOL . '* * * * * docker exec old111 php artisan schedule:run' . PHP_EOL);

        $writer = new CrontabWriter($path);
        $writer->writeAll([]);

        expect(trim(file_get_contents($path)))->toBe('');

        unlink($path);
    });
});

describe('remove()', function () {

    it('removes marker and cron entry for the given container', function () {
        $path = makeTempCrontab();
        $writer = new CrontabWriter($path);

        $job1 = makeJob(containerId: 'aaa111', jobName: 'laravel', schedule: '* * * * *', command: 'php artisan schedule:run');
        $job2 = makeJob(containerId: 'bbb222', jobName: 'laravel', schedule: '* * * * *', command: 'php artisan schedule:run');

        $writer->add($job1);
        $writer->add($job2);

        $writer->remove('aaa111');

        $lines = crontabLines($path);
        expect($lines)->toHaveCount(2);
        expect($lines[0])->toBe('# job:' . $job2->signature());
        expect($lines[1])->toBe('* * * * * docker exec bbb222 php artisan schedule:run');
        expect(file_get_contents($path))->not->toContain('aaa111');

        unlink($path);
    });

    it('removes all jobs when container has multiple jobs', function () {
        $path = makeTempCrontab();
        $writer = new CrontabWriter($path);

        $job1 = makeJob(containerId: 'aaa111', jobName: 'laravel', schedule: '* * * * *', command: 'php artisan schedule:run');
        $job2 = makeJob(containerId: 'aaa111', jobName: 'backup', schedule: '0 2 * * *', command: 'php artisan backup:run');
        $job3 = makeJob(containerId: 'bbb222', jobName: 'laravel', schedule: '* * * * *', command: 'php artisan schedule:run');

        $writer->add($job1);
        $writer->add($job2);
        $writer->add($job3);

        $writer->remove('aaa111');

        $lines = crontabLines($path);
        expect($lines)->toHaveCount(2);
        expect($lines[0])->toBe('# job:' . $job3->signature());
        expect($lines[1])->toBe('* * * * * docker exec bbb222 php artisan schedule:run');
        expect(file_get_contents($path))->not->toContain('aaa111');

        unlink($path);
    });

    it('leaves file unchanged when container has no entries', function () {
        $path = makeTempCrontab();
        $writer = new CrontabWriter($path);

        $job = makeJob(containerId: 'aaa111', jobName: 'laravel', schedule: '* * * * *', command: 'php artisan schedule:run');
        $writer->add($job);
        $contentBefore = file_get_contents($path);

        $writer->remove('nonexistent');

        expect(file_get_contents($path))->toBe($contentBefore);

        unlink($path);
    });

    it('results in empty file when last container is removed', function () {
        $path = makeTempCrontab();
        $writer = new CrontabWriter($path);

        $writer->add(makeJob(containerId: 'aaa111', jobName: 'laravel'));
        $writer->remove('aaa111');

        expect(trim(file_get_contents($path)))->toBe('');

        unlink($path);
    });

});

describe('has()', function () {

    it('returns true when exact job signature exists in crontab', function () {
        $path = makeTempCrontab();
        $writer = new CrontabWriter($path);
        $job = makeJob(containerId: 'aaa111', jobName: 'laravel', command: 'php artisan schedule:run');

        $writer->add($job);

        expect($writer->has($job))->toBeTrue();
        expect(file_get_contents($path))->toContain('# job:' . $job->signature());

        unlink($path);
    });

    it('returns false when job does not exist', function () {
        $path = makeTempCrontab();
        $writer = new CrontabWriter($path);
        $job = makeJob(containerId: 'aaa111', jobName: 'laravel');

        expect($writer->has($job))->toBeFalse();

        unlink($path);
    });

    it('returns true for same job with different schedule — schedule not part of signature', function () {
        $path = makeTempCrontab();
        $writer = new CrontabWriter($path);

        $job = makeJob(containerId: 'aaa111', jobName: 'laravel', schedule: '* * * * *', command: 'php artisan schedule:run');
        $jobUpdated = makeJob(containerId: 'aaa111', jobName: 'laravel', schedule: '* 2 * * *', command: 'php artisan schedule:run');

        $writer->add($job);

        expect($writer->has($jobUpdated))->toBeTrue(); // same signature
        expect($job->signature())->toBe($jobUpdated->signature());

        unlink($path);
    });

    it('returns false for job with different command — signatures differ', function () {
        $path = makeTempCrontab();
        $writer = new CrontabWriter($path);

        $oldJob = makeJob(containerId: 'aaa111', jobName: 'laravel', command: 'php artisan schedule:run');
        $newJob = makeJob(containerId: 'aaa111', jobName: 'laravel', command: 'php artisan app:test');

        $writer->add($oldJob);

        expect($writer->has($newJob))->toBeFalse();
        expect($writer->has($oldJob))->toBeTrue();

        unlink($path);
    });

    it('returns false after job is removed', function () {
        $path = makeTempCrontab();
        $writer = new CrontabWriter($path);
        $job = makeJob(containerId: 'aaa111', jobName: 'laravel');

        $writer->add($job);
        $writer->remove('aaa111');

        expect($writer->has($job))->toBeFalse();
        expect(trim(file_get_contents($path)))->toBe('');

        unlink($path);
    });

});
