<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\BookingActor;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Order;

final readonly class ReleaseBookingHoldAction
{
    public function handle(Order $order): void
    {
        $booking = Booking::query()->where('order_id', $order->id)->where('status', BookingStatus::PENDING_PAYMENT)->first();

        if (! $booking instanceof Booking) {
            return;
        }

        $booking->claims()->delete();
        $booking->update(['status' => BookingStatus::EXPIRED, 'cancelled_at' => now(), 'cancelled_by' => BookingActor::SYSTEM]);
    }
}
