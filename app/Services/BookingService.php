<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Booking;
use App\Models\Record;
use App\Models\RecordType;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class BookingService
{
    public const array DEFAULT_HOURS = [
        1 => [['start' => '09:00', 'end' => '17:00']],
        2 => [['start' => '09:00', 'end' => '17:00']],
        3 => [['start' => '09:00', 'end' => '17:00']],
        4 => [['start' => '09:00', 'end' => '17:00']],
        5 => [['start' => '09:00', 'end' => '17:00']],
    ];

    public const int HOLD_MINUTES = 35;

    public function __construct(private StripeService $stripe) {}

    public function isOpen(): bool
    {
        return once(fn (): bool => RecordType::query()->where('bookable', true)->exists());
    }

    public function timezone(): string
    {
        $timezone = config('site.booking_timezone');

        return is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
    }

    /**
     * @return array<int, list<array{start: string, end: string}>>
     */
    public function hours(): array
    {
        $saved = config('site.booking_hours');

        if (! is_array($saved)) {
            return self::DEFAULT_HOURS;
        }

        $hours = [];

        foreach (range(1, 7) as $weekday) {
            $ranges = $saved[$weekday] ?? $saved[(string) $weekday] ?? [];

            foreach (is_array($ranges) ? $ranges : [] as $range) {
                $start = is_array($range) ? $this->clock($range['start'] ?? null) : null;
                $end = is_array($range) ? $this->clock($range['end'] ?? null) : null;

                if ($start !== null && $end !== null && $start < $end) {
                    $hours[$weekday][] = ['start' => $start, 'end' => $end];
                }
            }
        }

        return $hours;
    }

    public function interval(): int
    {
        return $this->minutes('booking_interval', 30, 5, 240);
    }

    public function buffer(): int
    {
        return $this->minutes('booking_buffer', 0, 0, 240);
    }

    public function noticeMinutes(): int
    {
        return $this->number('booking_notice_hours', 24, 0, 720) * 60;
    }

    public function daysAhead(): int
    {
        return $this->number('booking_days_ahead', 60, 1, 365);
    }

    public function cancelCutoffHours(): int
    {
        return $this->number('booking_cancel_cutoff_hours', 24, 0, 720);
    }

    public function reminderHours(): int
    {
        return $this->number('booking_reminder_hours', 24, 0, 168);
    }

    /**
     * @return list<array{from: string, to: string}>
     */
    public function closedRanges(): array
    {
        $saved = config('site.booking_closed');
        $ranges = [];

        foreach (is_array($saved) ? $saved : [] as $range) {
            $from = is_array($range) ? $this->date($range['from'] ?? null) : null;
            $to = is_array($range) ? $this->date($range['to'] ?? null) : null;

            if ($from !== null) {
                $ranges[] = ['from' => $from, 'to' => $to !== null && $to >= $from ? $to : $from];
            }
        }

        return $ranges;
    }

    public function isBookable(Record $record): bool
    {
        return $record->recordType->bookable && $record->isLiveInLocale() && $this->duration($record) > 0;
    }

    public function duration(Record $record): int
    {
        $minutes = $record->data[RecordTypePresets::DURATION_FIELD] ?? null;

        if (! is_numeric($minutes)) {
            return 0;
        }

        $minutes = (int) $minutes;

        return $minutes >= 5 && $minutes <= 720 && $minutes % 5 === 0 ? $minutes : 0;
    }

    public function price(Record $record): ?string
    {
        $price = $record->data[RecordTypePresets::PRICE_FIELD] ?? null;

        return is_numeric($price) && (float) $price > 0 ? (string) $price : null;
    }

    public function chargesOnBooking(Record $record): bool
    {
        return $this->stripe->configured()
            && filter_var($record->data[RecordTypePresets::CHARGE_FIELD] ?? false, FILTER_VALIDATE_BOOLEAN)
            && $this->price($record) !== null;
    }

    public function location(Record $record, ?string $locale = null): string
    {
        $value = $record->data[RecordTypePresets::LOCATION_FIELD] ?? null;

        if (is_array($value)) {
            $value = $value[$locale ?? app()->getLocale()] ?? $value[config()->string('app.default_locale', 'en')] ?? null;
        }

        return is_string($value) ? mb_trim($value) : '';
    }

    /**
     * @param  array<int, int|string>  $recordIds
     * @return Collection<int, Record>
     */
    public function services(array $recordIds = []): Collection
    {
        $ids = array_values(array_filter(array_map(fn (int|string $id): int => (int) $id, $recordIds)));

        return Record::query()
            ->with(['recordType', 'translations'])
            ->whereHas('recordType', fn (Builder $query): Builder => $query->where('bookable', true))
            ->publishedInLocale()
            ->when($ids !== [], fn (Builder $query): Builder => $query->whereKey($ids))
            ->orderBy('id')
            ->get()
            ->filter(fn (Record $record): bool => $this->isBookable($record))
            ->when($ids !== [], fn (Collection $records): Collection => $records->sortBy(fn (Record $record): int|false => array_search($record->id, $ids, true)))
            ->values();
    }

    public function formatTime(CarbonImmutable $instant, string $timezone, string $locale): string
    {
        return $instant->setTimezone($timezone)->locale($locale)->isoFormat('dddd D MMMM YYYY, LT');
    }

    public function formatDay(CarbonImmutable $instant, string $timezone, string $locale): string
    {
        return $instant->setTimezone($timezone)->locale($locale)->isoFormat('dddd D MMMM YYYY');
    }

    public function formatClock(CarbonImmutable $instant, string $timezone, string $locale): string
    {
        return $instant->setTimezone($timezone)->locale($locale)->isoFormat('LT');
    }

    /**
     * @param  array<string, string>  $query
     */
    public function manageUrl(Booking $booking, array $query = []): string
    {
        $url = resolve('localization')->getLocalizedURL(route('booking.manage', $booking->manage_token), $booking->locale);

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    public function serviceLocation(Booking $booking): string
    {
        $record = $booking->record_id === null ? null : Record::query()->find($booking->record_id);

        return $record instanceof Record ? $this->location($record, $booking->locale) : '';
    }

    public function feedToken(): string
    {
        $token = config('site.booking_feed_token');

        return is_string($token) ? $token : '';
    }

    private function clock(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $match) !== 1) {
            return $value === '24:00' ? '24:00' : null;
        }

        return ((int) $match[2]) % 5 === 0 ? $value : null;
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) mb_substr($value, 5, 2), (int) mb_substr($value, 8, 2), (int) mb_substr($value, 0, 4)) ? $value : null;
    }

    private function minutes(string $key, int $default, int $min, int $max): int
    {
        $value = $this->number($key, $default, $min, $max);

        return $value % 5 === 0 ? $value : $default;
    }

    private function number(string $key, int $default, int $min, int $max): int
    {
        $value = config('site.'.$key);

        return is_numeric($value) && (int) $value >= $min && (int) $value <= $max ? (int) $value : $default;
    }
}
