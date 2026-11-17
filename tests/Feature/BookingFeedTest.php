<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Settings;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00', 'UTC'));
});

it('serves confirmed bookings to the owner\'s calendar through the private link', function (): void {
    $token = str_repeat('a', 48);
    Settings::set(['booking_feed_token' => $token]);
    Booking::factory()->create(['name' => 'Ada Lovelace', 'starts_at' => CarbonImmutable::parse('2026-11-20 10:00', 'UTC')]);
    Booking::factory()->cancelled()->create(['name' => 'Cancelled Person']);
    Booking::factory()->create(['name' => 'Long Ago', 'starts_at' => CarbonImmutable::parse('2026-09-01 10:00', 'UTC')]);

    $response = $this->get(route('booking.feed', $token))->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/calendar')
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->getContent())->toContain('Ada Lovelace', 'DTSTART:20261120T100000Z')
        ->not->toContain('Cancelled Person', 'Long Ago');
});

it('shows nothing without the right link', function (): void {
    $this->get(route('booking.feed', str_repeat('b', 48)))->assertNotFound();

    Settings::set(['booking_feed_token' => str_repeat('a', 48)]);

    $this->get(route('booking.feed', str_repeat('b', 48)))->assertNotFound();
});
