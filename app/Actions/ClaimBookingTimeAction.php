<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Booking;
use App\Models\BookingClaim;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ClaimBookingTimeAction
{
    public function handle(Booking $booking): void
    {
        $rows = array_map(
            fn (CarbonImmutable $cell): array => ['booking_id' => $booking->id, 'starts_at' => $cell->format('Y-m-d H:i:s')],
            BookingClaim::cellsFor($booking->starts_at, $booking->duration_minutes + $booking->buffer_minutes),
        );

        try {
            DB::transaction(fn (): bool => BookingClaim::query()->insert($rows));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['time' => __('That time was just taken. Please pick another.')]);
        }
    }
}
