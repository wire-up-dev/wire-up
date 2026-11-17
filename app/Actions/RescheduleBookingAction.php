<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEvent;
use App\Enums\BookingActor;
use App\Enums\BookingStatus;
use App\Enums\WebhookEvent;
use App\Models\Booking;
use App\Notifications\BookingAlert;
use App\Notifications\BookingUpdate;
use App\Services\ActivityLog;
use App\Services\BookingAvailability;
use App\Services\BookingMessages;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class RescheduleBookingAction
{
    public function __construct(
        private BookingAvailability $availability,
        private BookingService $bookings,
        private ClaimBookingTimeAction $claim,
        private BookingMessages $messages,
    ) {}

    public function handle(Booking $booking, CarbonImmutable $start, BookingActor $by): void
    {
        throw_if(
            $booking->status !== BookingStatus::CONFIRMED || $start->minute % 5 !== 0,
            ValidationException::withMessages(['time' => __('This booking can no longer be moved.')]),
        );

        throw_if(
            $by === BookingActor::CUSTOMER && ! $this->availability->isOffered($booking->duration_minutes, $start, $booking->id),
            ValidationException::withMessages(['time' => __('That time is no longer available.')]),
        );

        DB::transaction(function () use ($booking, $start): void {
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            $locked->claims()->delete();
            $locked->update([
                'starts_at' => $start->utc(),
                'ends_at' => $start->utc()->addMinutes($locked->duration_minutes),
                'sequence' => $locked->sequence + 1,
                'reminded_at' => $this->bookings->reminderHours() === 0 || $start->lessThanOrEqualTo(now()->addHours($this->bookings->reminderHours())) ? now() : null,
            ]);

            $this->claim->handle($locked);
        });

        $booking->refresh();

        $this->messages->customer($booking, BookingUpdate::RESCHEDULED);
        $this->messages->webhook($booking, WebhookEvent::BOOKING_RESCHEDULED);

        if ($by === BookingActor::OWNER) {
            ActivityLog::record(ActivityEvent::BOOKING_RESCHEDULED, $booking);
        } else {
            $this->messages->owner($booking, BookingAlert::RESCHEDULED);
        }
    }
}
