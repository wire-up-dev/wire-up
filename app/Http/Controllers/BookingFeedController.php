<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\BookingCalendarFile;
use App\Services\BookingService;
use Illuminate\Http\Response;

final class BookingFeedController
{
    public function __invoke(BookingService $bookings, BookingCalendarFile $calendar, string $token): Response
    {
        $expected = $bookings->feedToken();

        abort_if($expected === '' || ! hash_equals($expected, $token), 404);

        $confirmed = Booking::query()
            ->where('status', BookingStatus::CONFIRMED)
            ->where('starts_at', '>=', now()->subDays(30))
            ->oldest('starts_at')
            ->get();

        return response($calendar->feed($confirmed), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
