<?php

declare(strict_types=1);

use App\Actions\CancelBookingAction;
use App\Actions\RescheduleBookingAction;
use App\Enums\BookingActor;
use App\Models\Booking;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;

return new class extends Component
{
    public Booking $booking;

    public string $day = '';

    public string $time = '';

    public function mount(Booking $booking): void
    {
        $this->booking = $booking;
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

    public function openReschedule(BookingService $bookings): void
    {
        $this->authorize('bookings.edit');

        $local = $this->booking->starts_at->setTimezone($bookings->timezone());
        $this->day = $local->format('Y-m-d');
        $this->time = $local->format('H:i');
        $this->resetValidation();

        Flux::modal('reschedule-booking')->show();
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

        Flux::modal('reschedule-booking')->close();
        Flux::toast(__('Booking moved.'), variant: 'success');
        $this->booking->refresh();
    }

    public function render(): View
    {
        return $this->view()
            ->title(__('Booking :reference', ['reference' => $this->booking->reference]))
            ->layout('layouts::admin');
    }
};
?>

@php
    $bookingService = resolve(\App\Services\BookingService::class);
    $siteZone = $bookingService->timezone();
    $order = $booking->order;
@endphp

<div class="max-w-3xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <flux:heading size="xl">{{ $booking->service_name }}</flux:heading>
            <x-admin.status-badge :color="$booking->status->color()">
                {{ $booking->status->label() }}</x-admin.status-badge>
        </div>
        @can('bookings.edit')
            @if ($booking->status === \App\Enums\BookingStatus::CONFIRMED)
                <div class="flex gap-2">
                    <flux:button
                        size="sm"
                        icon="arrows-right-left"
                        wire:click="openReschedule"
                    >{{ __('Move') }}</flux:button>
                    <flux:modal.trigger name="cancel-booking">
                        <flux:button size="sm" variant="danger" icon="x-mark">{{ __('Cancel booking') }}</flux:button>
                    </flux:modal.trigger>
                </div>
            @endif
        @endcan
    </div>

    @if ($booking->clashed)
        <flux:callout icon="exclamation-triangle" color="red">
            <flux:callout.heading>{{ __('This booking clashes with another one') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('The payment arrived after the checkout had expired and someone else had booked the time. Agree on a new time with them, or refund the order.') }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:card class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <flux:text>{{ __('When') }}</flux:text>
                <flux:text variant="strong">{{ $bookingService->formatTime($booking->starts_at, $siteZone, app()->getLocale()) }}</flux:text>
                <flux:text>{{ trans_choice(':count minute|:count minutes', $booking->duration_minutes) }} · {{ str_replace('_', ' ', $siteZone) }}</flux:text>
                @if ($booking->timezone !== $siteZone)
                    <flux:text>{{ __('Their time: :time', ['time' => $bookingService->formatTime($booking->starts_at, $booking->timezone, app()->getLocale()).' ('.str_replace('_', ' ', $booking->timezone).')']) }}</flux:text>
                @endif
            </div>
            <div>
                <flux:text>{{ __('Who') }}</flux:text>
                <flux:text variant="strong">{{ $booking->name ?: __('Blocked time') }}</flux:text>
                @if ($booking->email)
                    <flux:link href="mailto:{{ $booking->email }}">{{ $booking->email }}</flux:link>
                @endif
                @if ($booking->phone)
                    <flux:text>{{ $booking->phone }}</flux:text>
                @endif
            </div>
        </div>

        @if ($booking->notes)
            <div>
                <flux:text>{{ __('Notes') }}</flux:text>
                <flux:text variant="strong" class="whitespace-pre-line">{{ $booking->notes }}</flux:text>
            </div>
        @endif

        <flux:separator variant="subtle" />

        <div class="flex flex-wrap gap-x-8 gap-y-2">
            <flux:text>{{ __('Reference :reference', ['reference' => $booking->reference]) }}</flux:text>
            <flux:text>{{ __('Booked :date', ['date' => $booking->created_at->format('M d, Y H:i')]) }}</flux:text>
            @if ($booking->cancelled_at)
                <flux:text>{{ __('Cancelled :date', ['date' => $booking->cancelled_at->format('M d, Y H:i')]) }}</flux:text>
            @endif
            @if ($order)
                @can('orders.view')
                    <flux:link
                        href="{{ route('admin.orders-show', $order) }}"
                        wire:navigate
                    >{{ __('Order :reference', ['reference' => $order->reference]) }}</flux:link>
                @endcan
            @endif
        </div>
    </flux:card>

    @can('bookings.edit')
        <flux:modal name="cancel-booking" class="min-w-[22rem]">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Cancel this booking?') }}</flux:heading>
                    <flux:text class="mt-2">{{ $booking->email ? __('They get an email saying it’s cancelled.') : __('The time becomes free again.') }}</flux:text>
                    @if ($order && $order->status->canBeRefunded())
                        <flux:text class="mt-2">{{ __('The payment isn’t refunded automatically. Refund it from the order.') }}</flux:text>
                    @endif
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Keep it') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" wire:click="cancel">{{ __('Cancel booking') }}</flux:button>
                </div>
            </div>
        </flux:modal>

        <flux:modal name="reschedule-booking" class="w-full md:max-w-md">
            <form wire:submit="reschedule" class="space-y-6">
                <flux:heading size="lg">{{ __('Move booking') }}</flux:heading>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:date-picker wire:model="day" label="{{ __('Day') }}" />
                    <flux:input type="time" step="300" wire:model="time" label="{{ __('Time') }}" />
                </div>
                <flux:text>{{ __('Times are in :zone.', ['zone' => str_replace('_', ' ', $siteZone)]) }}</flux:text>
                <div class="flex justify-end gap-2">
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
    <flux:breadcrumbs>
        <flux:breadcrumbs.item href="{{ route('admin.bookings-index') }}" wire:navigate class="pl-3 md:pl-0">
            {{ __('Bookings') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $booking->reference }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>
@endsection
