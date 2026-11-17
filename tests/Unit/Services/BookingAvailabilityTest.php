<?php

declare(strict_types=1);

use App\Models\BookingClaim;
use App\Services\BookingAvailability;
use Carbon\CarbonImmutable;

/**
 * @param  array<string, mixed>  $overrides
 * @return array{timezone: string, hours: array<int, list<array{start: string, end: string}>>, interval: int, buffer: int, notice: int, daysAhead: int, closed: list<array{from: string, to: string}>}
 */
function bookingRules(array $overrides = []): array
{
    $weekday = [['start' => '09:00', 'end' => '12:00']];

    return [
        'timezone' => 'UTC',
        'hours' => [1 => $weekday, 2 => $weekday, 3 => $weekday, 4 => $weekday, 5 => $weekday],
        'interval' => 60,
        'buffer' => 0,
        'notice' => 0,
        'daysAhead' => 60,
        'closed' => [],
        ...$overrides,
    ];
}

/**
 * @param  list<CarbonImmutable>  $slots
 * @return list<string>
 */
function slotTimes(array $slots): array
{
    return array_map(fn (CarbonImmutable $slot): string => $slot->utc()->format('Y-m-d H:i'), $slots);
}

it('offers each start time within the weekly hours and none at the weekend', function (): void {
    $slots = BookingAvailability::compute(bookingRules(), 60, '2026-11-16', '2026-11-22', [], CarbonImmutable::parse('2026-11-16 00:00', 'UTC'));

    expect($slots)->toHaveCount(15)
        ->and(slotTimes($slots)[0])->toBe('2026-11-16 09:00')
        ->and(slotTimes($slots)[14])->toBe('2026-11-20 11:00');
});

it('steps by the interval even when sessions are longer', function (): void {
    $slots = BookingAvailability::compute(bookingRules(['interval' => 30]), 60, '2026-11-16', '2026-11-16', [], CarbonImmutable::parse('2026-11-16 00:00', 'UTC'));

    expect(slotTimes($slots))->toBe(['2026-11-16 09:00', '2026-11-16 09:30', '2026-11-16 10:00', '2026-11-16 10:30', '2026-11-16 11:00']);
});

it('leaves out times inside the notice period and beyond the booking horizon', function (): void {
    $now = CarbonImmutable::parse('2026-11-16 10:30', 'UTC');

    expect(slotTimes(BookingAvailability::compute(bookingRules(), 60, '2026-11-16', '2026-11-16', [], $now)))->toBe(['2026-11-16 11:00'])
        ->and(BookingAvailability::compute(bookingRules(['notice' => 60]), 60, '2026-11-16', '2026-11-16', [], $now))->toBeEmpty()
        ->and(slotTimes(BookingAvailability::compute(bookingRules(['daysAhead' => 1]), 60, '2026-11-01', '2026-11-30', [], $now)))->toBe(['2026-11-16 11:00', '2026-11-17 09:00', '2026-11-17 10:00', '2026-11-17 11:00']);
});

it('skips closed days and taken time, keeping the buffer clear', function (): void {
    $taken = array_map(fn (CarbonImmutable $cell): int => $cell->getTimestamp(), BookingClaim::cellsFor(CarbonImmutable::parse('2026-11-18 10:00', 'UTC'), 60));

    $slots = BookingAvailability::compute(
        bookingRules(['buffer' => 15, 'closed' => [['from' => '2026-11-17', 'to' => '2026-11-17']]]),
        60,
        '2026-11-17',
        '2026-11-18',
        $taken,
        CarbonImmutable::parse('2026-11-16 00:00', 'UTC'),
    );

    expect(slotTimes($slots))->toBe(['2026-11-18 11:00']);
});

it('skips the hour that does not exist when London springs forward', function (): void {
    $slots = BookingAvailability::compute(
        bookingRules(['timezone' => 'Europe/London', 'interval' => 30, 'hours' => [7 => [['start' => '00:00', 'end' => '04:00']]]]),
        30,
        '2026-03-29',
        '2026-03-29',
        [],
        CarbonImmutable::parse('2026-03-20 00:00', 'UTC'),
    );

    expect(slotTimes($slots))->toBe(['2026-03-29 00:00', '2026-03-29 00:30', '2026-03-29 01:00', '2026-03-29 01:30', '2026-03-29 02:00', '2026-03-29 02:30']);
});

it('offers the repeated hour only once when London falls back', function (): void {
    $slots = BookingAvailability::compute(
        bookingRules(['timezone' => 'Europe/London', 'hours' => [7 => [['start' => '00:00', 'end' => '03:00']]]]),
        60,
        '2026-10-25',
        '2026-10-25',
        [],
        CarbonImmutable::parse('2026-10-20 00:00', 'UTC'),
    );

    expect(slotTimes($slots))->toBe(['2026-10-24 23:00', '2026-10-25 01:00', '2026-10-25 02:00']);
});

it('places local hours correctly in zones with odd offsets', function (string $timezone, array $expected): void {
    $slots = BookingAvailability::compute(
        bookingRules(['timezone' => $timezone, 'interval' => 30, 'hours' => [1 => [['start' => '09:00', 'end' => '10:00']]]]),
        30,
        '2026-11-16',
        '2026-11-16',
        [],
        CarbonImmutable::parse('2026-11-10 00:00', 'UTC'),
    );

    expect(slotTimes($slots))->toBe($expected);
})->with([
    'Kathmandu' => ['Asia/Kathmandu', ['2026-11-16 03:15', '2026-11-16 03:45']],
    'New York' => ['America/New_York', ['2026-11-16 14:00', '2026-11-16 14:30']],
]);

it('lets a working day run until midnight', function (): void {
    $slots = BookingAvailability::compute(
        bookingRules(['hours' => [1 => [['start' => '23:00', 'end' => '24:00']]]]),
        60,
        '2026-11-16',
        '2026-11-16',
        [],
        CarbonImmutable::parse('2026-11-10 00:00', 'UTC'),
    );

    expect(slotTimes($slots))->toBe(['2026-11-16 23:00']);
});
