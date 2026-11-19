<?php

declare(strict_types=1);

use App\Actions\ClaimBookingTimeAction;
use App\Actions\CreateBookingAction;
use App\Enums\BookingActor;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

mutates(ClaimBookingTimeAction::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00', 'UTC'));
    openBookingHours(['booking_buffer' => 15]);
});

it('lets only one booking hold any five minutes of time', function (): void {
    $first = Booking::factory()->create(['starts_at' => CarbonImmutable::parse('2026-11-18 10:00', 'UTC'), 'buffer_minutes' => 15]);
    resolve(ClaimBookingTimeAction::class)->handle($first);

    $overlapping = Booking::factory()->create(['starts_at' => CarbonImmutable::parse('2026-11-18 11:10', 'UTC'), 'duration_minutes' => 30]);
    $after = Booking::factory()->create(['starts_at' => CarbonImmutable::parse('2026-11-18 11:15', 'UTC'), 'duration_minutes' => 30]);

    expect(fn () => resolve(ClaimBookingTimeAction::class)->handle($overlapping))->toThrow(ValidationException::class, 'That time was just taken.');

    resolve(ClaimBookingTimeAction::class)->handle($after);

    expect($first->claims()->count())->toBe(15)
        ->and($overlapping->claims()->count())->toBe(0)
        ->and($after->claims()->count())->toBe(6);
});

it('keeps the owner from double-booking even outside the weekly hours', function (): void {
    $create = resolve(CreateBookingAction::class);
    $create->handle(null, CarbonImmutable::parse('2026-11-18 20:00', 'UTC'), ['duration' => 60, 'service_name' => 'Dentist'], source: BookingActor::OWNER);

    expect(fn () => $create->handle(null, CarbonImmutable::parse('2026-11-18 20:30', 'UTC'), ['duration' => 30], source: BookingActor::OWNER))->toThrow(ValidationException::class)
        ->and(fn () => $create->handle(null, CarbonImmutable::parse('2026-11-18 21:03', 'UTC'), ['duration' => 30], source: BookingActor::OWNER))->toThrow(ValidationException::class)
        ->and(fn () => $create->handle(null, CarbonImmutable::parse('2026-11-18 22:00', 'UTC'), [], source: BookingActor::OWNER))->toThrow(ValidationException::class)
        ->and(Booking::query()->sole()->service_name)->toBe('Dentist');
});

it('refuses a visitor\'s time that the weekly hours do not offer', function (): void {
    expect(fn () => resolve(CreateBookingAction::class)->handle(bookableService(), CarbonImmutable::parse('2026-11-18 20:00', 'UTC'), []))->toThrow(ValidationException::class, 'That time is no longer available.');
});
