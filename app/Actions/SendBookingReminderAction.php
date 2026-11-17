<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Notifications\BookingUpdate;
use App\Services\BookingMessages;

final readonly class SendBookingReminderAction
{
    public function __construct(private BookingMessages $messages) {}

    public function handle(Booking $booking): bool
    {
        $marked = Booking::query()
            ->whereKey($booking->id)
            ->where('status', BookingStatus::CONFIRMED)
            ->whereNull('reminded_at')
            ->update(['reminded_at' => now()]);

        if ($marked === 0) {
            return false;
        }

        $this->messages->customer($booking->refresh(), BookingUpdate::REMINDER);

        return true;
    }
}
