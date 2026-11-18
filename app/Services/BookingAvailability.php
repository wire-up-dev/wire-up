<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BookingClaim;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final readonly class BookingAvailability
{
    public function __construct(private BookingService $bookings) {}

    /**
     * @param  array{timezone: string, hours: array<int, list<array{start: string, end: string}>>, interval: int, buffer: int, notice: int, daysAhead: int, closed: list<array{from: string, to: string}>}  $rules
     * @param  list<int>  $occupied
     * @return list<CarbonImmutable>
     */
    public static function compute(array $rules, int $duration, string $fromDate, string $toDate, array $occupied, CarbonImmutable $now): array
    {
        $timezone = $rules['timezone'];
        $today = $now->setTimezone($timezone)->format('Y-m-d');
        $first = max($fromDate, $today);
        $last = min($toDate, CarbonImmutable::createFromFormat('!Y-m-d', $today, 'UTC')->addDays($rules['daysAhead'])->format('Y-m-d'));
        $earliest = $now->addMinutes($rules['notice']);
        $taken = array_flip($occupied);
        $slots = [];

        for ($date = CarbonImmutable::createFromFormat('!Y-m-d', $first, 'UTC'); $date->format('Y-m-d') <= $last; $date = $date->addDay()) {
            $day = $date->format('Y-m-d');

            if (self::isClosed($day, $rules['closed'])) {
                continue;
            }

            foreach ($rules['hours'][$date->dayOfWeekIso] ?? [] as $range) {
                $from = self::toMinutes($range['start']);
                $until = self::toMinutes($range['end']);
                $rangeEnd = self::instant($day, $until, $timezone);

                for ($minute = $from; $minute + $duration <= $until; $minute += $rules['interval']) {
                    $start = self::instant($day, $minute, $timezone);

                    if (! $start instanceof CarbonImmutable || $start < $earliest || ($rangeEnd instanceof CarbonImmutable && $start->addMinutes($duration) > $rangeEnd)) {
                        continue;
                    }

                    if (array_any(BookingClaim::cellsFor($start, $duration + $rules['buffer']), fn (CarbonImmutable $cell): bool => isset($taken[$cell->getTimestamp()]))) {
                        continue;
                    }

                    $slots[$start->getTimestamp()] = $start;
                }
            }
        }

        ksort($slots);

        return array_values($slots);
    }

    /**
     * @return array{timezone: string, hours: array<int, list<array{start: string, end: string}>>, interval: int, buffer: int, notice: int, daysAhead: int, closed: list<array{from: string, to: string}>}
     */
    public function rules(int $minimumNotice = 0): array
    {
        return [
            'timezone' => $this->bookings->timezone(),
            'hours' => $this->bookings->hours(),
            'interval' => $this->bookings->interval(),
            'buffer' => $this->bookings->buffer(),
            'notice' => max($this->bookings->noticeMinutes(), $minimumNotice),
            'daysAhead' => $this->bookings->daysAhead(),
            'closed' => $this->bookings->closedRanges(),
        ];
    }

    /**
     * @return list<CarbonImmutable>
     */
    public function slots(int $duration, string $fromDate, string $toDate, ?int $ignoreBookingId = null, int $minimumNotice = 0): array
    {
        if ($duration <= 0 || ! $this->bookings->availabilityIsSet()) {
            return [];
        }

        $rules = $this->rules($minimumNotice);
        $rangeStart = CarbonImmutable::createFromFormat('!Y-m-d', $fromDate, $rules['timezone'])->subDay()->utc();
        $rangeEnd = CarbonImmutable::createFromFormat('!Y-m-d', $toDate, $rules['timezone'])->addDays(2)->utc();

        $occupied = BookingClaim::query()
            ->whereBetween('starts_at', [$rangeStart, $rangeEnd])
            ->when($ignoreBookingId !== null, fn (Builder $query): Builder => $query->where('booking_id', '!=', $ignoreBookingId))
            ->pluck('starts_at')
            ->map(fn (mixed $cell): int => ($cell instanceof CarbonInterface ? $cell : CarbonImmutable::parse((string) $cell, 'UTC'))->getTimestamp())
            ->values()
            ->all();

        return self::compute($rules, $duration, $fromDate, $toDate, $occupied, CarbonImmutable::now());
    }

    public function isOffered(int $duration, CarbonImmutable $start, ?int $ignoreBookingId = null, int $minimumNotice = 0): bool
    {
        $day = $start->setTimezone($this->bookings->timezone())->format('Y-m-d');

        return array_any(
            $this->slots($duration, $day, $day, $ignoreBookingId, $minimumNotice),
            fn (CarbonImmutable $slot): bool => $slot->equalTo($start),
        );
    }

    /**
     * @param  list<array{from: string, to: string}>  $closed
     */
    private static function isClosed(string $day, array $closed): bool
    {
        return array_any($closed, fn (array $range): bool => $day >= $range['from'] && $day <= $range['to']);
    }

    private static function toMinutes(string $clock): int
    {
        return ((int) mb_substr($clock, 0, 2)) * 60 + (int) mb_substr($clock, 3, 2);
    }

    private static function instant(string $day, int $minute, string $timezone): ?CarbonImmutable
    {
        if ($minute >= 1440) {
            return CarbonImmutable::createFromFormat('!Y-m-d', $day, $timezone)->addDay()->utc();
        }

        $clock = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
        $local = CarbonImmutable::createFromFormat('Y-m-d H:i', $day.' '.$clock, $timezone);

        return $local->format('Y-m-d H:i') === $day.' '.$clock ? $local->utc() : null;
    }
}
