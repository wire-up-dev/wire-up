<?php

declare(strict_types=1);

use App\Actions\CancelBookingAction;
use App\Actions\DeleteBookingAction;
use App\Actions\RescheduleBookingAction;
use App\Enums\BookingActor;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;

return new class extends Component
{
    public Booking $booking;

    public string $day = '';

    public string $time = '';

    public bool $showMove = false;

    public function mount(Booking $booking, BookingService $bookings): void
    {
        $this->booking = $booking;

        if (request()->boolean('move') && $booking->status === BookingStatus::CONFIRMED && Gate::allows('bookings.edit')) {
            $this->prefillMove($bookings);
        }
    }

    /**
     * @throws ApiErrorException
     */
    public function cancel(CancelBookingAction $cancel): void
    {
        $this->authorize('bookings.edit');

        if ($cancel->handle($this->booking, BookingActor::OWNER)) {
            Flux::toast(__('Booking cancelled.'), variant: 'success');
        }

        Flux::modal('cancel-booking')->close();
        $this->booking->refresh();
    }

    public function delete(DeleteBookingAction $delete): void
    {
        $this->authorize('bookings.delete');

        if ($delete->handle($this->booking)) {
            Flux::toast(__('Booking deleted.'), variant: 'success');

            $this->redirectRoute('admin.bookings-index', navigate: true);
        }
    }

    public function openReschedule(BookingService $bookings): void
    {
        $this->authorize('bookings.edit');

        $this->prefillMove($bookings);
    }

    public function reschedule(RescheduleBookingAction $reschedule, BookingService $bookings): void
    {
        $this->authorize('bookings.edit');

        $validated = $this->validate([
            'day' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
        ], ['time.date_format' => __('Enter the time as hours and minutes, like 14:30.')], ['day' => __('day'), 'time' => __('time')]);

        try {
            $reschedule->handle($this->booking, CarbonImmutable::createFromFormat('Y-m-d H:i', $validated['day'].' '.$validated['time'], $bookings->timezone()), BookingActor::OWNER);
        } catch (ValidationException $exception) {
            $this->addError('time', (string) collect($exception->errors())->flatten()->first());

            return;
        }

        $this->showMove = false;
        Flux::toast(__('Booking moved.'), variant: 'success');
        $this->booking->refresh();
    }

    public function render(): View
    {
        return $this->view()
            ->title(__('Booking :reference', ['reference' => $this->booking->reference]))
            ->layout('layouts::admin');
    }

    private function prefillMove(BookingService $bookings): void
    {
        $local = $this->booking->starts_at->setTimezone($bookings->timezone());
        $this->day = $local->format('Y-m-d');
        $this->time = $local->format('H:i');
        $this->resetValidation();
        $this->showMove = true;
    }
};
?>

@php
    $bookingService = resolve(\App\Services\BookingService::class);
    $siteZone = $bookingService->timezone();
    $order = $booking->order;
    $canChange = $booking->status === \App\Enums\BookingStatus::CONFIRMED;
    $canCancel = $canChange && $booking->ends_at->isFuture();
@endphp

<div class="grid items-stretch gap-10 md:grid-cols-5">
    <div class="md:col-span-3">
        <div class="mb-10 max-w-5xl space-y-6">
            <flux:fieldset class="pb-6">
                <div class="flex items-center justify-between gap-3">
                    <flux:legend class="mb-0!">{{ $booking->service_name }}</flux:legend>
                    <x-admin.status-badge :color="$booking->status->color()">
                        {{ $booking->status->label() }}</x-admin.status-badge>
                </div>
                <flux:description>
                    {{ $bookingService->formatTime($booking->starts_at, $siteZone, app()->getLocale()) }}
                </flux:description>

                @if ($booking->clashed)
                    <flux:callout icon="exclamation-triangle" color="red" class="mt-4">
                        <flux:callout.heading>{{ __('This booking clashes with another one') }}</flux:callout.heading>
                        <flux:callout.text>
                            {{ __('The payment arrived after the checkout had expired and someone else had booked the time. Agree on a new time with them, or refund the order.') }}</flux:callout.text>
                    </flux:callout>
                @endif

                <dl class="mt-6 grid gap-x-6 gap-y-4 sm:grid-cols-[8rem_1fr]">
                    <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Length') }}</dt>
                    <dd>{{ trans_choice(':count minute|:count minutes', $booking->duration_minutes) }}</dd>
                    <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Time zone') }}</dt>
                    <dd>{{ str_replace('_', ' ', $siteZone) }}</dd>
                    @if ($booking->timezone !== $siteZone)
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Their time') }}</dt>
                        <dd>
                            {{ $bookingService->formatTime($booking->starts_at, $booking->timezone, app()->getLocale()) }} ({{ str_replace('_', ' ', $booking->timezone) }})
                        </dd>
                    @endif
                    <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Name') }}</dt>
                    <dd class="break-words">{{ $booking->name ?: __('Blocked time') }}</dd>
                    @if ($booking->email)
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Email') }}</dt>
                        <dd class="break-words">{{ $booking->email }}</dd>
                    @endif
                    @if ($booking->phone)
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Phone') }}</dt>
                        <dd>{{ $booking->phone }}</dd>
                    @endif
                    @if ($order)
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Order') }}</dt>
                        <dd>
                            @can('orders.view')
                                <a
                                    class="underline"
                                    href="{{ route('admin.orders-show', $order) }}"
                                    wire:navigate
                                >{{ $order->reference }}</a>
                            @else
                                {{ $order->reference }}
                            @endcan
                        </dd>
                    @endif
                </dl>
            </flux:fieldset>

            @if ($booking->notes)
                <flux:separator />

                <flux:fieldset>
                    <flux:legend>{{ __('Notes') }}</flux:legend>
                    <flux:text class="mt-4 leading-relaxed whitespace-pre-line">{{ $booking->notes }}</flux:text>
                </flux:fieldset>
            @endif

            <flux:text size="sm" variant="subtle">
                {{ __('Reference :reference', ['reference' => $booking->reference]) }} · {{ $booking->source === \App\Enums\BookingActor::OWNER ? __('Added by staff') : __('Booked online') }}
                @if ($booking->locale && count(resolve('localization')->getActiveLocales()) > 1)
                    · {{ mb_strtoupper($booking->locale) }}
                @endif
            </flux:text>
        </div>
    </div>

    <div class="mb-10 md:col-span-2 md:mb-0">
        <flux:card class="flex flex-col gap-6 md:sticky md:top-24">
            @if ($canChange)
                @can('bookings.edit')
                    <flux:button variant="primary" icon="arrows-right-left" class="w-full" wire:click="openReschedule">
                        {{ __('Move') }}
                    </flux:button>
                @endcan
            @elseif ($booking->email)
                <flux:button :href="'mailto:'.$booking->email" variant="primary" icon="envelope" class="w-full">
                    {{ __('Email') }}
                </flux:button>
            @endif

            <div class="grid grid-cols-2 gap-4">
                <flux:button :href="route('admin.bookings-index')" wire:navigate icon="arrow-left">
                    {{ __('Back') }}
                </flux:button>
                @if ($canCancel)
                    @can('bookings.edit')
                        <flux:modal.trigger name="cancel-booking">
                            <flux:button variant="danger" icon="x-mark" class="w-full">{{ __('Cancel') }}</flux:button>
                        </flux:modal.trigger>
                    @endcan
                @elseif ($booking->canBeDeleted())
                    @can('bookings.delete')
                        <flux:modal.trigger name="delete-booking">
                            <flux:button variant="danger" icon="trash" class="w-full">{{ __('Delete') }}</flux:button>
                        </flux:modal.trigger>
                    @endcan
                @endif
            </div>

            <flux:text size="sm">
                @if ($booking->cancelled_at)
                    {{ __('Cancelled') }} {{ $booking->cancelled_at->diffForHumans() }}
                @else
                    {{ __('Booked') }} {{ $booking->created_at->diffForHumans() }}
                @endif
            </flux:text>
        </flux:card>
    </div>

    @if ($booking->canBeDeleted())
        @can('bookings.delete')
            <flux:modal name="delete-booking" class="min-w-[22rem]">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Delete booking') }}</flux:heading>
                        <flux:text class="mt-2">{{ __('Are you sure you want to delete this booking? This cannot be undone.') }}</flux:text>
                    </div>
                    <div class="flex gap-2">
                        <flux:spacer />
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" wire:click="delete">{{ __('Delete') }}</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endcan
    @endif

    @can('bookings.edit')
        @if ($canCancel)
            <flux:modal name="cancel-booking" class="min-w-[22rem]">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Cancel this booking?') }}</flux:heading>
                        <flux:text class="mt-2">{{ $booking->email ? __('They get an email saying it’s cancelled.') : __('The time becomes free again.') }}</flux:text>
                        @if ($order && $order->status->canBeRefunded())
                            <flux:text class="mt-2">{{ __('The payment isn’t refunded automatically. Refund it from the order.') }}</flux:text>
                        @endif
                    </div>
                    <div class="flex gap-2">
                        <flux:spacer />
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Keep it') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" wire:click="cancel">{{ __('Cancel booking') }}</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif

        <flux:modal name="reschedule-booking" wire:model.self="showMove" class="w-full md:max-w-md">
            <form wire:submit="reschedule" class="space-y-6">
                <flux:heading size="lg">{{ __('Move booking') }}</flux:heading>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:date-picker wire:model="day" label="{{ __('Day') }}" />
                    <flux:input type="time" step="300" wire:model="time" label="{{ __('Time') }}" />
                </div>
                <flux:text>{{ __('Times are in :zone.', ['zone' => str_replace('_', ' ', $siteZone)]) }}</flux:text>
                <div class="flex gap-2">
                    <flux:spacer />
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Move booking') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
</div>

@section('header-content')
    <flux:breadcrumbs class="hidden md:flex">
        <flux:breadcrumbs.item :href="route('admin.bookings-index')" wire:navigate>
            {{ __('Bookings') }}
        </flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $booking->name ?: $booking->reference }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <flux:dropdown class="md:hidden">
        <flux:navbar.item icon-trailing="chevron-down">
            {{ \Illuminate\Support\Str::limit($booking->name ?: $booking->reference, 22) }}</flux:navbar.item>

        <flux:navmenu>
            <flux:navmenu.item :href="route('admin.bookings-index')" wire:navigate>
                {{ __('Bookings') }}</flux:navmenu.item>
        </flux:navmenu>
    </flux:dropdown>
@endsection
