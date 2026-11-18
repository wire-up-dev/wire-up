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
use App\Services\BookingMessages;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\ApiErrorException;

final readonly class CancelBookingAction
{
    public function __construct(private BookingMessages $messages, private CancelOrderAction $cancelOrder) {}

    /**
     * @throws ApiErrorException
     */
    public function handle(Booking $booking, BookingActor $by): bool
    {
        if ($booking->status === BookingStatus::PENDING_PAYMENT && $booking->order !== null) {
            $this->cancelOrder->handle($booking->order);

            return $booking->refresh()->status === BookingStatus::EXPIRED;
        }

        $cancelled = DB::transaction(function () use ($booking, $by): bool {
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if (! $locked->status->holdsTime()) {
                return false;
            }

            $locked->claims()->delete();
            $locked->update([
                'status' => BookingStatus::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $by,
                'clashed' => false,
                'sequence' => $locked->sequence + 1,
            ]);

            return true;
        });

        if (! $cancelled) {
            return false;
        }

        $booking->refresh();

        $this->messages->customer($booking, BookingUpdate::CANCELLED);
        $this->messages->webhook($booking, WebhookEvent::BOOKING_CANCELLED);

        if ($by === BookingActor::OWNER) {
            ActivityLog::record(ActivityEvent::BOOKING_CANCELLED, $booking);
        } else {
            $this->messages->owner($booking, BookingAlert::CANCELLED);
        }

        return true;
    }
}
