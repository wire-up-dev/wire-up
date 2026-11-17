<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BookingActor;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingClaim;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Booking>
 */
final class BookingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => Str::upper(Str::random(8)),
            'manage_token' => Str::random(48),
            'status' => BookingStatus::CONFIRMED,
            'source' => BookingActor::CUSTOMER,
            'record_id' => null,
            'service_name' => 'Coaching session',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'starts_at' => CarbonImmutable::now()->utc()->addDays(3)->setTime(15, 0),
            'ends_at' => fn (array $attributes): CarbonImmutable => CarbonImmutable::parse($attributes['starts_at'])->addMinutes((int) $attributes['duration_minutes']),
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => null,
            'notes' => null,
            'locale' => 'en',
            'timezone' => 'UTC',
            'user_id' => null,
            'order_id' => null,
            'clashed' => false,
            'sequence' => 0,
            'confirmed_at' => now(),
            'reminded_at' => null,
            'cancelled_at' => null,
            'cancelled_by' => null,
        ];
    }

    public function pendingPayment(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => BookingStatus::PENDING_PAYMENT, 'confirmed_at' => null]);
    }

    public function cancelled(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => BookingStatus::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => BookingActor::CUSTOMER]);
    }

    public function claimed(): self
    {
        return $this->afterCreating(function (Booking $booking): void {
            foreach (BookingClaim::cellsFor($booking->starts_at, $booking->duration_minutes + $booking->buffer_minutes) as $cell) {
                $booking->claims()->create(['starts_at' => $cell]);
            }
        });
    }
}
