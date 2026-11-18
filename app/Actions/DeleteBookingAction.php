<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEvent;
use App\Models\Booking;
use App\Services\ActivityLog;

final readonly class DeleteBookingAction
{
    public function handle(Booking $booking): bool
    {
        if (! $booking->canBeDeleted()) {
            return false;
        }

        $booking->delete();

        ActivityLog::record(ActivityEvent::BOOKING_DELETED, $booking);

        return true;
    }
}
