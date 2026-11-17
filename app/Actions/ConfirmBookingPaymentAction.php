<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\BookingStatus;
use App\Enums\WebhookEvent;
use App\Models\Booking;
use App\Models\Order;
use App\Notifications\BookingAlert;
use App\Notifications\BookingUpdate;
use App\Services\BookingMessages;
use Illuminate\Validation\ValidationException;

final readonly class ConfirmBookingPaymentAction
{
    public function __construct(private ClaimBookingTimeAction $claim, private BookingMessages $messages) {}

    public function handle(Order $order): bool
    {
        $booking = Booking::query()->lockForUpdate()->where('order_id', $order->id)->first();

        if (! $booking instanceof Booking) {
            return false;
        }

        if (! in_array($booking->status, [BookingStatus::PENDING_PAYMENT, BookingStatus::EXPIRED], true)) {
            return true;
        }

        $clashed = false;

        if ($booking->status === BookingStatus::EXPIRED) {
            try {
                $this->claim->handle($booking);
            } catch (ValidationException) {
                $clashed = true;
            }
        }

        $booking->update([
            'status' => BookingStatus::CONFIRMED,
            'confirmed_at' => now(),
            'cancelled_at' => null,
            'cancelled_by' => null,
            'clashed' => $clashed,
        ]);

        $this->messages->webhook($booking, WebhookEvent::BOOKING_CONFIRMED);

        if ($clashed) {
            $this->messages->owner($booking, BookingAlert::CLASHED);

            return true;
        }

        $this->messages->customer($booking, BookingUpdate::CONFIRMED);
        $this->messages->owner($booking, BookingAlert::NEW);

        return true;
    }
}
