@props(['title', 'monthLabel', 'canGoBack', 'canGoForward', 'days', 'date', 'times', 'selected', 'timezone', 'timezones'])

@php
    $pillClass = 'w-full cursor-pointer rounded-(--wire-btn-radius) px-4 py-3 text-center font-medium transition';
    $selectedClass = 'bg-(--wire-primary-bg) text-(--wire-primary-text)';
    $idleClass = 'bg-(--wire-card-bg) text-(--wire-card-text) hover:opacity-80';
@endphp

<div class="space-y-5" data-booking-calendar>
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h3 class="text-lg font-medium">{{ $title }}</h3>
        <label
            class="relative inline-flex cursor-pointer items-center gap-1 text-sm text-(--wire-accent)"
            title="{{ __('Change time zone') }}"
        >
            <span class="sr-only">{{ __('Times are shown in') }}</span>
            <span aria-hidden="true">{{ str_replace('_', ' ', $timezone) }}</span>
            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
            </svg>
            <select wire:model.live="timezone" class="absolute inset-0 cursor-pointer opacity-0" data-booking-timezone>
                @foreach ($timezones as $zone)
                    <option value="{{ $zone }}" @selected($zone === $timezone)>
                        {{ str_replace('_', ' ', $zone) }}
                    </option>
                @endforeach
            </select>
        </label>
    </div>

    <div class="flex items-center justify-between">
        <button
            type="button"
            wire:click="previousMonth"
            @disabled(! $canGoBack)
            class="cursor-pointer rounded-(--wire-btn-radius) bg-(--wire-card-bg) px-3 py-1.5 text-(--wire-card-text) disabled:cursor-default disabled:opacity-30"
            aria-label="{{ __('Previous month') }}"
        >
            &larr;
        </button>
        <p class="text-xl">{{ $monthLabel }}</p>
        <button
            type="button"
            wire:click="nextMonth"
            @disabled(! $canGoForward)
            class="cursor-pointer rounded-(--wire-btn-radius) bg-(--wire-card-bg) px-3 py-1.5 text-(--wire-card-text) disabled:cursor-default disabled:opacity-30"
            aria-label="{{ __('Next month') }}"
        >
            &rarr;
        </button>
    </div>

    @if ($days === [])
        <p class="opacity-70">{{ __('There are no free times this month.') }}</p>
    @else
        <div class="grid gap-4 sm:grid-cols-[1fr_1px_1fr]">
            <div
                class="[&::-webkit-scrollbar]:hidden relative max-h-[19rem] [scrollbar-width:none] space-y-2 overflow-y-auto overscroll-contain"
                x-data
                x-init="
                    const day = $el.querySelector('[data-selected-day]');
                    if (day) $el.scrollTop = day.offsetTop;
                "
                data-booking-days
            >
                @foreach ($days as $day)
                    <button
                        type="button"
                        wire:click="pickDate('{{ $day['date'] }}')"
                        wire:key="day-{{ $day['date'] }}"
                        data-available-day="{{ $day['date'] }}"
                        @if ($date === $day['date']) data-selected-day @endif
                        @class([$pillClass, $date === $day['date'] ? $selectedClass : $idleClass])
                    >
                        {{ $day['label'] }}
                    </button>
                @endforeach
            </div>

            <div class="hidden bg-current opacity-15 sm:block"></div>

            <div class="grid grid-cols-3 content-start gap-2 sm:grid-cols-2">
                @foreach ($times as $time)
                    <button
                        type="button"
                        wire:click="pickSlot('{{ $time['value'] }}')"
                        wire:key="slot-{{ $time['value'] }}"
                        data-slot="{{ $time['value'] }}"
                        @class([$pillClass, $selected === $time['value'] ? $selectedClass : $idleClass])
                    >
                        {{ $time['label'] }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif
</div>
