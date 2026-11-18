<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\BookingActor;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Models\Booking;
use App\Models\Order;
use Stripe\Exception\ApiErrorException;

final readonly class CancelRefundedBookingAction
{
    public function __construct(private CancelBookingAction $cancel) {}

    /**
     * @throws ApiErrorException
     */
    public function handle(Order $order): bool
    {
        $booking = Booking::query()->where('order_id', $order->id)->first();

        if ($order->status !== OrderStatus::REFUNDED || ! $booking instanceof Booking || $booking->status !== BookingStatus::CONFIRMED || $booking->ends_at->isPast()) {
            return false;
        }

        return $this->cancel->handle($booking, BookingActor::OWNER);
    }
}
