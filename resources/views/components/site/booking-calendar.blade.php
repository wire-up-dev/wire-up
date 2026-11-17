@props(['days', 'weekdays', 'monthLabel', 'canGoBack', 'canGoForward', 'date', 'times', 'slot', 'timezone', 'timezones'])

<div class="grid gap-8 md:grid-cols-2" data-booking-calendar>
    <div>
        <div class="mb-4 flex items-center justify-between">
            <button
                type="button"
                wire:click="previousMonth"
                @disabled(! $canGoBack)
                class="rounded-(--wire-btn-radius) px-3 py-1 text-lg disabled:opacity-30"
                aria-label="{{ __('Previous month') }}"
            >
                &larr;
            </button>
            <p class="font-medium">{{ $monthLabel }}</p>
            <button
                type="button"
                wire:click="nextMonth"
                @disabled(! $canGoForward)
                class="rounded-(--wire-btn-radius) px-3 py-1 text-lg disabled:opacity-30"
                aria-label="{{ __('Next month') }}"
            >
                &rarr;
            </button>
        </div>
        <div class="grid grid-cols-7 gap-1 text-center text-sm">
            @foreach ($weekdays as $weekday)
                <span class="pb-2 text-xs uppercase opacity-60">{{ $weekday }}</span>
            @endforeach
            @foreach ($days as $day)
                @if (! $day['inMonth'])
                    <span></span>
                @elseif ($day['available'])
                    <button
                        type="button"
                        wire:click="pickDate('{{ $day['date'] }}')"
                        wire:key="day-{{ $day['date'] }}"
                        data-available-day="{{ $day['date'] }}"
                        @class([
                            'aspect-square rounded-(--wire-btn-radius) font-medium transition',
                            'bg-(--wire-primary-bg) text-(--wire-primary-text)' => $date === $day['date'],
                            'bg-(--wire-card-bg) text-(--wire-card-text) hover:opacity-80' => $date !== $day['date'],
                        ])
                    >
                        {{ $day['day'] }}
                    </button>
                @else
                    <span class="flex aspect-square items-center justify-center opacity-30">{{ $day['day'] }}</span>
                @endif
            @endforeach
        </div>
    </div>

    <div class="space-y-4">
        @if ($date === '')
            <p class="opacity-70">{{ __('Pick a day to see the free times.') }}</p>
        @else
            <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-3">
                @foreach ($times as $time)
                    <button
                        type="button"
                        wire:click="pickSlot('{{ $time['value'] }}')"
                        wire:key="slot-{{ $time['value'] }}"
                        data-slot="{{ $time['value'] }}"
                        @class([
                            'wire-btn rounded-(--wire-btn-radius) px-3 py-2 text-sm font-medium transition',
                            'bg-(--wire-primary-bg) text-(--wire-primary-text) [--wire-btn-border:var(--wire-primary-border)]' => $slot === $time['value'],
                            'bg-(--wire-secondary-bg) text-(--wire-secondary-text) [--wire-btn-border:var(--wire-secondary-border)] hover:opacity-80' => $slot !== $time['value'],
                        ])
                    >
                        {{ $time['label'] }}
                    </button>
                @endforeach
            </div>
        @endif

        <label class="block text-sm">
            <span class="mb-1 block opacity-70">{{ __('Times are shown in') }}</span>
            <select
                wire:model.live="timezone"
                class="wire-field w-full rounded-(--wire-btn-radius) bg-(--wire-input-bg) px-3 py-2 text-(--wire-input-text)"
            >
                @foreach ($timezones as $zone)
                    <option value="{{ $zone }}" @selected($zone === $timezone)>
                        {{ str_replace('_', ' ', $zone) }}
                    </option>
                @endforeach
            </select>
        </label>
    </div>
</div>
