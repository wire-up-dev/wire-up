<?php

declare(strict_types=1);

use App\Actions\CreateBookingAction;
use App\Models\Booking;
use App\Notifications\BookingUpdate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00', 'UTC'));
    openBookingHours();
    Notification::fake();
});

it('reminds visitors once, inside the reminder window', function (): void {
    $soon = Booking::factory()->create(['starts_at' => CarbonImmutable::parse('2026-11-17 07:00', 'UTC')]);
    $later = Booking::factory()->create(['starts_at' => CarbonImmutable::parse('2026-11-19 07:00', 'UTC')]);
    Booking::factory()->cancelled()->create(['starts_at' => CarbonImmutable::parse('2026-11-16 12:00', 'UTC')]);
    Booking::factory()->create(['email' => null, 'starts_at' => CarbonImmutable::parse('2026-11-16 12:00', 'UTC')]);

    Artisan::call('wireup:send-booking-reminders');
    Artisan::call('wireup:send-booking-reminders');

    Notification::assertSentOnDemandTimes(BookingUpdate::class, 1);
    Notification::assertSentOnDemand(BookingUpdate::class, fn (BookingUpdate $notification): bool => $notification->kind === BookingUpdate::REMINDER && $notification->booking->is($soon));

    expect($soon->refresh()->reminded_at)->not->toBeNull()
        ->and($later->refresh()->reminded_at)->toBeNull();
});

it('sends no reminders when they are switched off', function (): void {
    openBookingHours(['booking_reminder_hours' => 0]);
    Booking::factory()->create(['starts_at' => CarbonImmutable::parse('2026-11-16 12:00', 'UTC')]);

    Artisan::call('wireup:send-booking-reminders');

    Notification::assertSentOnDemandTimes(BookingUpdate::class, 0);
});

it('does not remind a booking made or moved inside the window', function (): void {
    $service = bookableService();
    $booking = resolve(CreateBookingAction::class)->handle($service, CarbonImmutable::parse('2026-11-16 12:00', 'UTC'), ['email' => 'ada@example.com']);

    expect($booking->reminded_at)->not->toBeNull();

    $later = resolve(CreateBookingAction::class)->handle($service, CarbonImmutable::parse('2026-11-20 12:00', 'UTC'), ['email' => 'ada@example.com']);

    expect($later->reminded_at)->toBeNull();
});
