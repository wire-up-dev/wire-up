<?php

declare(strict_types=1);

namespace App\Traits;

use App\Services\BookingAvailability;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use DateTimeZone;

trait PicksBookingSlot
{
    public string $timezone = '';

    public string $month = '';

    public string $date = '';

    public string $slot = '';

    /**
     * @var array<string, list<string>>|null
     */
    private ?array $slotCache = null;

    private string $slotCacheKey = '';

    abstract protected function slotDuration(): int;

    public function useTimezone(string $timezone): void
    {
        $this->timezone = $timezone;
        $this->updatedTimezone();
    }

    public function updatedTimezone(): void
    {
        if (! in_array($this->timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            $this->timezone = resolve(BookingService::class)->timezone();
        }

        $this->month = $this->clampMonth($this->month);
        $this->date = '';
        $this->slot = '';
    }

    public function previousMonth(): void
    {
        $this->changeMonth(-1);
    }

    public function nextMonth(): void
    {
        $this->changeMonth(1);
    }

    public function pickDate(string $date): void
    {
        $this->date = array_key_exists($date, $this->monthSlots()) ? $date : '';
        $this->slot = '';
    }

    public function pickSlot(string $slot): void
    {
        foreach ($this->monthSlots() as $day => $slots) {
            if (in_array($slot, $slots, true)) {
                $this->date = $day;
                $this->slot = $slot;
                $this->slotPicked();

                return;
            }
        }

        $this->slot = '';
    }

    /**
     * @return array<string, list<string>>
     */
    public function monthSlots(): array
    {
        $duration = $this->slotDuration();
        $key = implode('|', [$this->month, $this->visitorZone(), $duration, $this->ignoredBookingId(), $this->slotMinimumNotice()]);

        if ($this->slotCache !== null && $this->slotCacheKey === $key) {
            return $this->slotCache;
        }

        $this->slotCacheKey = $key;
        $this->slotCache = $this->freshMonthSlots($duration);

        return $this->slotCache;
    }

    /**
     * @return list<array{date: string, label: string}>
     */
    public function availableDays(): array
    {
        return array_map(fn (string $day): array => [
            'date' => $day,
            'label' => CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC')->locale(app()->getLocale())->isoFormat('ddd, MMMM D'),
        ], array_keys($this->monthSlots()));
    }

    public function activeDate(): string
    {
        $slots = $this->monthSlots();

        return array_key_exists($this->date, $slots) ? $this->date : (string) array_key_first($slots);
    }

    public function monthLabel(): string
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', $this->month, 'UTC') ?: CarbonImmutable::now();

        return $month->locale(app()->getLocale())->isoFormat('MMMM YYYY');
    }

    public function canGoBack(): bool
    {
        return $this->month > $this->firstMonth();
    }

    public function canGoForward(): bool
    {
        return $this->month < $this->lastMonth();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function slotsForDate(): array
    {
        return array_map(fn (string $value): array => [
            'value' => $value,
            'label' => CarbonImmutable::parse($value)->setTimezone($this->visitorZone())->locale(app()->getLocale())->isoFormat('LT'),
        ], $this->monthSlots()[$this->activeDate()] ?? []);
    }

    public function chosenLabel(): string
    {
        $start = $this->chosenStart();

        return $start instanceof CarbonImmutable ? resolve(BookingService::class)->formatTime($start, $this->visitorZone(), app()->getLocale()) : '';
    }

    /**
     * @return list<string>
     */
    public function timezoneOptions(): array
    {
        $zones = DateTimeZone::listIdentifiers();

        if (! in_array($this->timezone, $zones, true)) {
            $zones[] = $this->timezone;
            sort($zones);
        }

        return $zones;
    }

    protected function ignoredBookingId(): ?int
    {
        return null;
    }

    protected function slotMinimumNotice(): int
    {
        return 0;
    }

    protected function slotPicked(): void {}

    protected function moveToOpenMonth(): void
    {
        while ($this->monthSlots() === [] && $this->canGoForward()) {
            $this->changeMonth(1);
        }
    }

    protected function initSlotPicker(): void
    {
        $this->timezone = resolve(BookingService::class)->timezone();
        $this->month = $this->firstMonth();
    }

    protected function chosenStart(): ?CarbonImmutable
    {
        if (! in_array($this->slot, $this->monthSlots()[$this->date] ?? [], true)) {
            return null;
        }

        return CarbonImmutable::parse($this->slot)->utc();
    }

    /**
     * @return array<string, list<string>>
     */
    private function freshMonthSlots(int $duration): array
    {
        $siteZone = resolve(BookingService::class)->timezone();
        $start = CarbonImmutable::createFromFormat('!Y-m', $this->month, $this->visitorZone());

        if ($duration <= 0 || ! $start instanceof CarbonImmutable) {
            return [];
        }

        $from = $start->setTimezone($siteZone)->format('Y-m-d');
        $until = $start->endOfMonth()->setTimezone($siteZone)->format('Y-m-d');
        $grouped = [];

        foreach (resolve(BookingAvailability::class)->slots($duration, $from, $until, $this->ignoredBookingId(), $this->slotMinimumNotice()) as $slot) {
            $local = $slot->setTimezone($this->visitorZone());

            if ($local->format('Y-m') === $this->month) {
                $grouped[$local->format('Y-m-d')][] = $slot->utc()->format('Y-m-d\TH:i:s\Z');
            }
        }

        return $grouped;
    }

    private function visitorZone(): string
    {
        return $this->timezone !== '' ? $this->timezone : resolve(BookingService::class)->timezone();
    }

    private function changeMonth(int $step): void
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', $this->month, 'UTC') ?: CarbonImmutable::now();

        $this->month = $this->clampMonth($month->addMonthsNoOverflow($step)->format('Y-m'));
        $this->date = '';
        $this->slot = '';
    }

    private function clampMonth(string $month): string
    {
        return max($this->firstMonth(), min($this->lastMonth(), $month));
    }

    private function firstMonth(): string
    {
        return CarbonImmutable::now()->setTimezone($this->visitorZone())->format('Y-m');
    }

    private function lastMonth(): string
    {
        return CarbonImmutable::now()->addDays(resolve(BookingService::class)->daysAhead())->setTimezone($this->visitorZone())->format('Y-m');
    }
}
