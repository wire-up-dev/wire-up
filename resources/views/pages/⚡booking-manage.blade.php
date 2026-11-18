<?php

declare(strict_types=1);

use App\Actions\CancelBookingAction;
use App\Actions\FulfillCheckoutAction;
use App\Actions\RescheduleBookingAction;
use App\Enums\BookingActor;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\BookingService;
use App\Traits\PicksBookingSlot;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;

return new class extends Component
{
    use PicksBookingSlot;

    #[Locked]
    public int $bookingId;

    public bool $rescheduling = false;

    public string $notice = '';

    public function mount(string $token, FulfillCheckoutAction $fulfil, CancelBookingAction $cancel): void
    {
        $booking = Booking::query()->where('manage_token', $token)->first();

        abort_unless($booking instanceof Booking, 404);

        $this->bookingId = $booking->id;
        $sessionId = request()->query('session_id');

        if (is_string($sessionId) && $booking->order !== null && $booking->order->stripe_session_id === $sessionId) {
            rescue(fn (): ?\App\Models\Order => $fulfil->handle($sessionId), report: false);
        }

        if (request()->query('cancelled') === '1' && $booking->status === BookingStatus::PENDING_PAYMENT) {
            rescue(fn (): bool => $cancel->handle($booking, BookingActor::CUSTOMER), false, report: false);
        }

        $this->initSlotPicker();
        $this->timezone = $booking->timezone;
    }

    public function booking(): Booking
    {
        return Booking::query()->findOrFail($this->bookingId);
    }

    public function canChange(): bool
    {
        $booking = $this->booking();

        return $booking->status === BookingStatus::CONFIRMED
            && $booking->starts_at->greaterThan(now()->addHours(resolve(BookingService::class)->cancelCutoffHours()));
    }

    /**
     * @throws ApiErrorException
     */
    public function cancel(CancelBookingAction $cancel): void
    {
        if (! $this->canChange()) {
            return;
        }

        $cancel->handle($this->booking(), BookingActor::CUSTOMER);

        $this->rescheduling = false;
        $this->notice = __('Your booking is cancelled. We have sent you an email to confirm it.');
    }

    public function startRescheduling(): void
    {
        $this->rescheduling = $this->canChange();
        $this->moveToOpenMonth();
    }

    public function reschedule(RescheduleBookingAction $reschedule): void
    {
        $start = $this->chosenStart();

        if (! $this->canChange() || ! $start instanceof \Carbon\CarbonImmutable) {
            $this->addError('time', __('Pick a time first.'));

            return;
        }

        try {
            $reschedule->handle($this->booking(), $start, BookingActor::CUSTOMER);
        } catch (ValidationException $exception) {
            $this->slot = '';
            $this->addError('time', (string) collect($exception->errors())->flatten()->first());

            return;
        }

        $this->rescheduling = false;
        $this->notice = __('Your booking has moved. We have sent you the new details.');
    }

    public function render(): View
    {
        return $this->view()->title(__('Your booking'));
    }

    protected function slotDuration(): int
    {
        return $this->booking()->duration_minutes;
    }

    protected function ignoredBookingId(): int
    {
        return $this->bookingId;
    }
};
?>

@php
    $booking = $this->booking();
    $bookings = resolve(\App\Services\BookingService::class);
    $buttonClass = 'wire-btn inline-flex cursor-pointer items-center justify-center rounded-(--wire-btn-radius) px-5 py-2.5 font-medium transition hover:opacity-90';
@endphp

<section class="w-full">
    <div class="mx-auto max-w-3xl space-y-8 px-(--wire-gutter) py-16" data-booking-manage>
        <h1 class="text-(length:--wire-heading-size) tracking-tight">{{ __('Your booking') }}</h1>

        @if ($notice !== '')
            <p
                class="wire-field rounded-(--wire-radius) bg-(--wire-input-bg) p-4 text-(--wire-input-text)"
                data-booking-notice
            >
                {{ $notice }}
            </p>
        @endif

        <div class="wire-card space-y-2 rounded-(--wire-radius) bg-(--wire-card-bg) p-6 text-(--wire-card-text)">
            <p class="text-lg font-medium">{{ $booking->service_name }}</p>
            <p>
                {{ $bookings->formatTime($booking->starts_at, $timezone ?: $booking->timezone, app()->getLocale()) }} ({{ str_replace('_', ' ', $timezone ?: $booking->timezone) }})
            </p>
            <p class="text-sm opacity-70">
                {{ __('Booking reference: :reference', ['reference' => $booking->reference]) }}
            </p>
            <p
                class="text-sm font-medium"
                data-booking-status
                @if ($booking->status === \App\Enums\BookingStatus::PENDING_PAYMENT) wire:poll.5s @endif
            >
                @if ($booking->status === \App\Enums\BookingStatus::PENDING_PAYMENT)
                    {{ __('We are waiting for your payment to go through. This page updates once it does.') }}
                @elseif ($booking->status === \App\Enums\BookingStatus::EXPIRED)
                    {{ __('This booking was not paid, so the time was released.') }}
                @elseif ($booking->status === \App\Enums\BookingStatus::CANCELLED)
                    {{ __('This booking is cancelled.') }}
                @else
                    {{ __('Confirmed') }}
                @endif
            </p>
        </div>

        @if ($this->canChange())
            @if ($rescheduling)
                <div class="space-y-6">
                    <x-site.booking-calendar
                        :title="__('Pick a new time')"
                        :month-label="$this->monthLabel()"
                        :can-go-back="$this->canGoBack()"
                        :can-go-forward="$this->canGoForward()"
                        :days="$this->availableDays()"
                        :date="$this->activeDate()"
                        :times="$this->slotsForDate()"
                        :selected="$this->slot"
                        :timezone="$timezone"
                        :timezones="$this->timezoneOptions()"
                    />
                    @error('time')
                        <p class="inline-block rounded-(--wire-radius) bg-red-100 px-2 py-0.5 text-sm font-medium text-red-700">
                            {{ $message }}
                        </p>
                    @enderror
                    <div class="flex flex-wrap gap-3">
                        <button
                            type="button"
                            wire:click="reschedule"
                            @disabled($this->slot === '')
                            class="{{ $buttonClass }} bg-(--wire-primary-bg) text-(--wire-primary-text) [--wire-btn-border:var(--wire-primary-border)] disabled:opacity-50"
                        >
                            {{ __('Move to this time') }}
                        </button>
                        <button
                            type="button"
                            wire:click="$set('rescheduling', false)"
                            class="{{ $buttonClass }} bg-(--wire-secondary-bg) text-(--wire-secondary-text) [--wire-btn-border:var(--wire-secondary-border)]"
                        >
                            {{ __('Keep my booking') }}
                        </button>
                    </div>
                </div>
            @else
                <div class="flex flex-wrap gap-3">
                    <button
                        type="button"
                        wire:click="startRescheduling"
                        class="{{ $buttonClass }} bg-(--wire-primary-bg) text-(--wire-primary-text) [--wire-btn-border:var(--wire-primary-border)]"
                    >
                        {{ __('Move to another time') }}
                    </button>
                    <button
                        type="button"
                        wire:click="cancel"
                        wire:confirm="{{ __('Cancel this booking?') }}"
                        class="{{ $buttonClass }} bg-(--wire-secondary-bg) text-(--wire-secondary-text) [--wire-btn-border:var(--wire-secondary-border)]"
                    >
                        {{ __('Cancel booking') }}
                    </button>
                </div>
            @endif
        @elseif ($booking->status === \App\Enums\BookingStatus::CONFIRMED)
            <p class="opacity-70">
                {{ __('This booking is too close to change online. Please get in touch if you need to.') }}
            </p>
        @endif
    </div>
</section>
