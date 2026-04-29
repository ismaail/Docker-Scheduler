<?php

declare(strict_types=1);

use App\Scheduler\ScheduleParser;

function parser(): ScheduleParser
{
    return new ScheduleParser();
}

/*
|--------------------------------------------------------------------------
| @every minute(s)
|--------------------------------------------------------------------------
|
*/

describe('@every <n>m', function () {

    it('converts @every 1m to * * * * *', function () {
        expect(parser()->parse('@every 1m'))->toBe('* * * * *');
    });

    it('converts @every 2m to */2 * * * *', function () {
        expect(parser()->parse('@every 2m'))->toBe('*/2 * * * *');
    });

    it('converts @every 5m to */5 * * * *', function () {
        expect(parser()->parse('@every 5m'))->toBe('*/5 * * * *');
    });

    it('converts @every 10m to */10 * * * *', function () {
        expect(parser()->parse('@every 10m'))->toBe('*/10 * * * *');
    });

    it('converts @every 15m to */15 * * * *', function () {
        expect(parser()->parse('@every 15m'))->toBe('*/15 * * * *');
    });

    it('converts @every 15m to */13 * * * *', function () {
        expect(parser()->parse('@every 13m'))->toBe('*/13 * * * *');
    });

    it('converts @every 30m to */30 * * * *', function () {
        expect(parser()->parse('@every 30m'))->toBe('*/30 * * * *');
    });

    it('converts @every 45m to */45 * * * *', function () {
        expect(parser()->parse('@every 45m'))->toBe('*/45 * * * *');
    });

    it('throws when minutes exceed 59', function () {
        expect(fn () => parser()->parse('@every 60m'))->toThrow(InvalidArgumentException::class);
    });
});

/*
|--------------------------------------------------------------------------
| @every hour(s)
|--------------------------------------------------------------------------
|
*/

describe('@every <n>h', function () {

    it('converts @every 1h to 0 * * * *', function () {
        expect(parser()->parse('@every 1h'))->toBe('0 * * * *');
    });

    it('converts @every 2h to 0 */2 * * *', function () {
        expect(parser()->parse('@every 2h'))->toBe('0 */2 * * *');
    });

    it('converts @every 6h to 0 */6 * * *', function () {
        expect(parser()->parse('@every 6h'))->toBe('0 */6 * * *');
    });

    it('converts @every 12h to 0 */12 * * *', function () {
        expect(parser()->parse('@every 12h'))->toBe('0 */12 * * *');
    });

    it('converts @every 23h to 0 */23 * * *', function () {
        expect(parser()->parse('@every 23h'))->toBe('0 */23 * * *');
    });

    it('throws when hours exceed 23', function () {
        expect(fn () => parser()->parse('@every 24h'))->toThrow(InvalidArgumentException::class);
    });
});

/*
|--------------------------------------------------------------------------
| @every day(s)
|--------------------------------------------------------------------------
|
*/

describe('@every <n>d', function () {

    it('converts @every 1d to 0 0 * * *', function () {
        expect(parser()->parse('@every 1d'))->toBe('0 0 * * *');
    });

    it('converts @every 2d to 0 0 */2 * *', function () {
        expect(parser()->parse('@every 2d'))->toBe('0 0 */2 * *');
    });

    it('converts @every 7d to 0 0 */7 * *', function () {
        expect(parser()->parse('@every 7d'))->toBe('0 0 */7 * *');
    });

    it('throws when days exceed 31', function () {
        expect(fn () => parser()->parse('@every 32d'))->toThrow(InvalidArgumentException::class);
    });
});

/*
|--------------------------------------------------------------------------
| @every second(s)
|--------------------------------------------------------------------------
|
|
|
*/

describe('@every <n>s', function () {

    it('throws for seconds — minimum interval is 1 minute', function () {
        expect(fn () => parser()->parse('@every 30s'))->toThrow(InvalidArgumentException::class);
    });

    it('throws for @every 1s', function () {
        expect(fn () => parser()->parse('@every 1s'))->toThrow(InvalidArgumentException::class);
    });
});

/*
|--------------------------------------------------------------------------
| Aliases
|--------------------------------------------------------------------------
|
*/

describe('aliases', function () {

    it('passes through @hourly', function () {
        expect(parser()->parse('@hourly'))->toBe('@hourly');
    });

    it('passes through @daily', function () {
        expect(parser()->parse('@daily'))->toBe('@daily');
    });

    it('passes through @weekly', function () {
        expect(parser()->parse('@weekly'))->toBe('@weekly');
    });

    it('passes through @monthly', function () {
        expect(parser()->parse('@monthly'))->toBe('@monthly');
    });

    it('passes through @yearly', function () {
        expect(parser()->parse('@yearly'))->toBe('@yearly');
    });

    it('passes through @midnight', function () {
        expect(parser()->parse('@midnight'))->toBe('@midnight');
    });

    it('normalizes alias to lowercase', function () {
        expect(parser()->parse('@Daily'))->toBe('@daily');
        expect(parser()->parse('@HOURLY'))->toBe('@hourly');
    });
});

/*
|--------------------------------------------------------------------------
| Standard
|--------------------------------------------------------------------------
|
*/

describe('standard cron expressions', function () {

    it('passes through * * * * *', function () {
        expect(parser()->parse('* * * * *'))->toBe('* * * * *');
    });

    it('passes through */5 * * * *', function () {
        expect(parser()->parse('*/5 * * * *'))->toBe('*/5 * * * *');
    });

    it('passes through 0 * * * *', function () {
        expect(parser()->parse('0 * * * *'))->toBe('0 * * * *');
    });

    it('passes through 0 0 * * *', function () {
        expect(parser()->parse('0 0 * * *'))->toBe('0 0 * * *');
    });

    it('passes through 0 2 * * 1', function () {
        expect(parser()->parse('0 2 * * 1'))->toBe('0 2 * * 1');
    });

    it('throws for invalid cron expression', function () {
        expect(fn () => parser()->parse('invalid'))->toThrow(InvalidArgumentException::class);
    });

    it('throws for incomplete cron expression', function () {
        expect(fn () => parser()->parse('* * * *'))->toThrow(InvalidArgumentException::class);
    });
});

/*
|--------------------------------------------------------------------------
| Edge cases
|--------------------------------------------------------------------------
|
*/

describe('edge cases', function () {

    it('throws for empty string', function () {
        expect(fn () => parser()->parse(''))->toThrow(InvalidArgumentException::class);
    });

    it('throws for @every with no duration', function () {
        expect(fn () => parser()->parse('@every'))->toThrow(InvalidArgumentException::class);
    });

    it('throws for @every with invalid unit', function () {
        expect(fn () => parser()->parse('@every 5w'))->toThrow(InvalidArgumentException::class);
    });

    it('throws for @every with zero value', function () {
        expect(fn () => parser()->parse('@every 0m'))->toThrow(InvalidArgumentException::class);
    });

    it('trims whitespace before parsing', function () {
        expect(parser()->parse('  * * * * *  '))->toBe('* * * * *');
        expect(parser()->parse('  @every 5m  '))->toBe('*/5 * * * *');
    });
});
