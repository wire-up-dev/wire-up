<?php

declare(strict_types=1);

use App\Actions\UpdateSettingsAction;
use App\Services\BookingService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

return new class extends Component
{
    public string $timezone = 'UTC';

    /**
     * @var array<int|string, list<array{start: string, end: string}>>
     */
    public array $hours = [];

    public int $interval = 30;

    public int $buffer = 0;

    public int $notice = 24;

    public int $daysAhead = 60;

    public int $cutoff = 24;

    public int $reminder = 24;

    /**
     * @var list<array{from: string, to: string}>
     */
    public array $closed = [];

    public function mount(BookingService $bookings): void
    {
        $this->timezone = $bookings->timezone();
        $this->hours = array_replace(array_fill_keys(range(1, 7), []), $bookings->hours());
        $this->interval = $bookings->interval();
        $this->buffer = $bookings->buffer();
        $this->notice = intdiv($bookings->noticeMinutes(), 60);
        $this->daysAhead = $bookings->daysAhead();
        $this->cutoff = $bookings->cancelCutoffHours();
        $this->reminder = $bookings->reminderHours();
        $this->closed = $bookings->closedRanges();
    }

    public function addRange(int $weekday): void
    {
        $this->hours[$weekday][] = ['start' => '09:00', 'end' => '17:00'];
    }

    public function removeRange(int $weekday, int $index): void
    {
        unset($this->hours[$weekday][$index]);
        $this->hours[$weekday] = array_values($this->hours[$weekday] ?? []);
    }

    public function addClosed(): void
    {
        $this->closed[] = ['from' => now()->format('Y-m-d'), 'to' => now()->format('Y-m-d')];
    }

    public function removeClosed(int $index): void
    {
        unset($this->closed[$index]);
        $this->closed = array_values($this->closed);
    }

    public function update(UpdateSettingsAction $action): void
    {
        $this->authorize('settings.edit');

        $this->validate([
            'timezone' => ['required', 'timezone:all'],
            'hours.*.*.start' => ['required', 'date_format:H:i', 'regex:/[05]$/'],
            'hours.*.*.end' => ['required', 'regex:/^(([01]\d|2[0-3]):[0-5][05]|24:00)$/'],
            'interval' => ['required', Rule::in([5, 10, 15, 20, 30, 45, 60, 90, 120])],
            'buffer' => ['required', Rule::in([0, 5, 10, 15, 20, 30, 45, 60])],
            'notice' => ['required', 'integer', 'min:0', 'max:720'],
            'daysAhead' => ['required', 'integer', 'min:1', 'max:365'],
            'cutoff' => ['required', 'integer', 'min:0', 'max:720'],
            'reminder' => ['required', 'integer', 'min:0', 'max:168'],
            'closed.*.from' => ['required', 'date_format:Y-m-d'],
            'closed.*.to' => ['required', 'date_format:Y-m-d', 'after_or_equal:closed.*.from'],
        ], [
            'hours.*.*.start.*' => __('Use a start time on a 5-minute step, like 09:00 or 09:15.'),
            'hours.*.*.end.*' => __('Use an end time on a 5-minute step, like 17:00, or 24:00 for midnight.'),
            'closed.*.to.after_or_equal' => __('The last closed day can’t be before the first.'),
        ], [
            'daysAhead' => __('days ahead'),
            'closed.*.from' => __('first closed day'),
            'closed.*.to' => __('last closed day'),
        ]);

        foreach ($this->hours as $weekday => $ranges) {
            foreach ($ranges as $index => $range) {
                if ($range['start'] >= $range['end']) {
                    $this->addError("hours.{$weekday}.{$index}.end", __('The end time must be after the start time.'));

                    return;
                }
            }
        }

        $action->handle([
            'booking_timezone' => $this->timezone,
            'booking_hours' => array_filter($this->hours),
            'booking_interval' => $this->interval,
            'booking_buffer' => $this->buffer,
            'booking_notice_hours' => $this->notice,
            'booking_days_ahead' => $this->daysAhead,
            'booking_cancel_cutoff_hours' => $this->cutoff,
            'booking_reminder_hours' => $this->reminder,
            'booking_closed' => $this->closed,
        ]);

        Flux::toast(__('Booking settings have been updated.'), variant: 'success');
    }

    public function newFeedLink(UpdateSettingsAction $action): void
    {
        $this->authorize('settings.edit');

        $action->handle(['booking_feed_token' => Str::random(48)]);

        Flux::toast(__('New calendar link made. Subscribe to it again in your calendar app.'), variant: 'success');
    }

    public function feedUrl(): string
    {
        $token = resolve(BookingService::class)->feedToken();

        return $token === '' ? '' : route('booking.feed', $token);
    }

    public function render(): View
    {
        return $this->view()
            ->title(__('Availability'))
            ->layout('layouts::admin');
    }
};
?>

@php
    $weekdays = collect(range(1, 7))->mapWithKeys(fn (int $day): array => [$day => \Carbon\CarbonImmutable::parse('2026-11-16')->addDays($day - 1)->locale(app()->getLocale())->isoFormat('dddd')]);
    $feedUrl = $this->feedUrl();
@endphp

<x-admin.settings-layout>
    <form
        wire:submit="update"
        wire:warn-dirty="{{ __('Leaving? Changes you made may not be saved.') }}"
        class="max-w-2xl space-y-10"
    >
        <flux:select
            wire:model="timezone"
            variant="listbox"
            searchable
            label="{{ __('Your time zone') }}"
            description="{{ __('Your hours below are in this time zone. Visitors see times in their own.') }}"
        >
            @foreach (\DateTimeZone::listIdentifiers() as $zone)
                <flux:select.option value="{{ $zone }}">{{ str_replace('_', ' ', $zone) }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="space-y-4">
            <flux:heading>{{ __('Weekly hours') }}</flux:heading>
            @foreach ($weekdays as $weekday => $label)
                <div
                    wire:key="weekday-{{ $weekday }}"
                    class="flex flex-wrap items-start gap-4 border-b border-zinc-100 pb-4 dark:border-white/5"
                >
                    <flux:text variant="strong" class="w-28 pt-2">{{ Str::ucfirst($label) }}</flux:text>
                    <div class="flex-1 space-y-2">
                        @forelse ($hours[$weekday] ?? [] as $index => $range)
                            <div wire:key="range-{{ $weekday }}-{{ $index }}" class="flex items-start gap-2">
                                <flux:input
                                    type="text"
                                    size="sm"
                                    wire:model="hours.{{ $weekday }}.{{ $index }}.start"
                                    :aria-label="__('Start')"
                                    placeholder="09:00"
                                    class="max-w-24"
                                />
                                <span class="pt-1.5">–</span>
                                <flux:input
                                    type="text"
                                    size="sm"
                                    wire:model="hours.{{ $weekday }}.{{ $index }}.end"
                                    :aria-label="__('End')"
                                    placeholder="17:00"
                                    class="max-w-24"
                                />
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="x-mark"
                                    wire:click="removeRange({{ $weekday }}, {{ $index }})"
                                    :aria-label="__('Remove')"
                                />
                            </div>
                            <flux:error name="hours.{{ $weekday }}.{{ $index }}.start" />
                            <flux:error name="hours.{{ $weekday }}.{{ $index }}.end" />
                        @empty
                            <flux:text class="pt-2">{{ __('Closed') }}</flux:text>
                        @endforelse
                    </div>
                    <flux:button
                        size="sm"
                        variant="ghost"
                        icon="plus"
                        wire:click="addRange({{ $weekday }})"
                    >{{ __('Add hours') }}</flux:button>
                </div>
            @endforeach
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <flux:select wire:model="interval" label="{{ __('Start times every') }}">
                @foreach ([5, 10, 15, 20, 30, 45, 60, 90, 120] as $minutes)
                    <flux:select.option :value="$minutes">
                        {{ trans_choice(':count minute|:count minutes', $minutes) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="buffer" label="{{ __('Break after each booking') }}">
                @foreach ([0, 5, 10, 15, 20, 30, 45, 60] as $minutes)
                    <flux:select.option :value="$minutes">
                        {{ $minutes === 0 ? __('None') : trans_choice(':count minute|:count minutes', $minutes) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input
                type="number"
                min="0"
                wire:model="notice"
                label="{{ __('Book at least this many hours ahead') }}"
            />
            <flux:input
                type="number"
                min="1"
                wire:model="daysAhead"
                label="{{ __('Book up to this many days ahead') }}"
            />
            <flux:input
                type="number"
                min="0"
                wire:model="cutoff"
                label="{{ __('Visitors can cancel or move until this many hours before') }}"
            />
            <flux:input
                type="number"
                min="0"
                wire:model="reminder"
                label="{{ __('Send a reminder this many hours before') }}"
                description="{{ __('0 sends no reminder.') }}"
            />
        </div>

        <div class="space-y-3">
            <flux:heading>{{ __('Closed days') }}</flux:heading>
            @foreach ($closed as $index => $range)
                <div wire:key="closed-{{ $index }}" class="flex flex-wrap items-start gap-2">
                    <flux:input type="date" size="sm" wire:model="closed.{{ $index }}.from" :aria-label="__('From')" />
                    <span class="pt-1.5">–</span>
                    <flux:input type="date" size="sm" wire:model="closed.{{ $index }}.to" :aria-label="__('To')" />
                    <flux:button
                        size="sm"
                        variant="ghost"
                        icon="x-mark"
                        wire:click="removeClosed({{ $index }})"
                        :aria-label="__('Remove')"
                    />
                    <flux:error name="closed.{{ $index }}.to" class="w-full" />
                </div>
            @endforeach
            <flux:button size="sm" icon="plus" wire:click="addClosed">{{ __('Add closed days') }}</flux:button>
        </div>

        <div>
            <flux:button type="submit" variant="primary" icon="check">{{ __('Update') }}</flux:button>
        </div>
    </form>

    <div class="mt-12 max-w-2xl space-y-3">
        <flux:heading>{{ __('Calendar feed') }}</flux:heading>
        <flux:text>{{ __('Subscribe to this private link in Google Calendar, Apple Calendar or Outlook to see your bookings there. Anyone with the link can see them.') }}</flux:text>
        @if ($feedUrl !== '')
            <flux:input :value="$feedUrl" readonly copyable />
        @endif
        <flux:button
            size="sm"
            icon="arrow-path"
            wire:click="newFeedLink"
        >{{ $feedUrl === '' ? __('Make a calendar link') : __('Make a new link') }}</flux:button>
    </div>
</x-admin.settings-layout>

@section('header-content')
    <flux:breadcrumbs class="hidden md:flex">
        <flux:breadcrumbs.item href="{{ route('admin.settings-general') }}" wire:navigate>
            {{ __('Settings') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Availability') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>
@endsection
