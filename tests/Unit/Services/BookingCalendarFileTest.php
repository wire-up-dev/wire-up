<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Settings;
use App\Services\BookingCalendarFile;
use Carbon\CarbonImmutable;

it('writes a calendar file for the customer in UTC with a stable id', function (): void {
    Settings::set(['title' => ['en' => 'Calm Coaching']]);
    $booking = Booking::factory()->make([
        'reference' => 'ABCD1234',
        'service_name' => 'Intro call',
        'starts_at' => CarbonImmutable::parse('2026-11-20 15:00', 'UTC'),
        'duration_minutes' => 30,
        'sequence' => 2,
    ]);

    $ics = resolve(BookingCalendarFile::class)->forCustomer($booking, 'Zoom; link, sent by email');

    expect($ics)->toStartWith("BEGIN:VCALENDAR\r\n")
        ->toEndWith("END:VCALENDAR\r\n")
        ->toContain("METHOD:PUBLISH\r\n", 'UID:booking-ABCD1234@', "DTSTART:20261120T150000Z\r\n", "DTEND:20261120T153000Z\r\n", "SEQUENCE:2\r\n", 'SUMMARY:Intro call · Calm Coaching', 'LOCATION:Zoom\; link\, sent by email', 'STATUS:CONFIRMED');
});

it('marks a cancelled booking so calendars remove it', function (): void {
    $booking = Booking::factory()->make(['status' => BookingStatus::CANCELLED]);

    expect(resolve(BookingCalendarFile::class)->forCustomer($booking))->toContain('METHOD:CANCEL', 'STATUS:CANCELLED')->not->toContain('LOCATION:');
});

it('lists bookings with the visitor details in the owner feed and folds long lines', function (): void {
    $booking = Booking::factory()->make([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'notes' => "First line\rSecond line\n".str_repeat('é', 60),
    ]);

    $ics = resolve(BookingCalendarFile::class)->feed([$booking]);
    $lines = explode("\r\n", $ics);

    expect($ics)->toContain('X-WR-CALNAME:', 'SUMMARY:Coaching session · Ada Lovelace', 'DESCRIPTION:Ada Lovelace\nada@example.com\nFirst line\nSecond line\n')
        ->and(mb_substr_count($ics, "\r"))->toBe(mb_substr_count($ics, "\r\n"))
        ->and(collect($lines)->every(fn (string $line): bool => mb_strlen($line) <= 75))->toBeTrue()
        ->and(collect($lines)->contains(fn (string $line): bool => str_starts_with($line, ' ')))->toBeTrue();
});
