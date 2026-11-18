<?php

declare(strict_types=1);

use App\Enums\BlockType;
use App\Enums\BookingStatus;
use App\Enums\ContentStatus;
use App\Models\Booking;
use App\Models\Page;
use Carbon\CarbonImmutable;

it('books a time in the visitor’s own time zone, then moves it from the manage page', function (): void {
    $this->travelBack();
    openBookingHours(['booking_timezone' => 'America/Toronto', 'booking_cancel_cutoff_hours' => 0]);
    bookableService();

    $page = Page::factory()->create([
        'metadata' => ['published_locales' => ['en']],
        'status' => ContentStatus::PUBLISHED,
        'published_at' => now()->subDay(),
        'title' => 'Book a session',
    ]);
    $page->slugs()->create(['locale' => 'en', 'slug' => 'book']);
    $page->updateBlocks([
        ['id' => 'new-1', 'type' => 'booking', 'content' => array_replace_recursive(
            BlockType::BOOKING->defaultContent(),
            ['heading' => ['en' => '<p>Book a session</p>']],
        )],
    ]);

    $browser = visit(route('page', 'book'))->withTimezone('Asia/Tokyo');

    $browser->assertSee('Choose a date and time');

    assertScriptEventually($browser, "document.querySelector('[data-booking-timezone]').value", 'Asia/Tokyo');

    if ($browser->script("document.querySelectorAll('[data-available-day]').length") < 2) {
        $browser->click('[aria-label="Next month"]');

        assertScriptEventually($browser, "document.querySelectorAll('[data-available-day]').length > 1", true);
    }

    $lastDay = $browser->script("[...document.querySelectorAll('[data-available-day]')].at(-1).dataset.availableDay");
    $browser->click("[data-available-day=\"{$lastDay}\"]");

    assertScriptEventually($browser, "document.querySelector('[data-selected-day]')?.dataset.availableDay", $lastDay);

    $slot = $browser->script("document.querySelector('[data-slot]').dataset.slot");

    expect($browser->script("document.querySelector('[data-slot]').innerText.trim()"))
        ->toBe(CarbonImmutable::parse($slot)->setTimezone('Asia/Tokyo')->locale('en')->isoFormat('LT'));

    $browser->click("[data-slot=\"{$slot}\"]")
        ->assertSee('Your information')
        ->fill('[id^="booking-name-"]', 'Ada Lovelace')
        ->fill('[id^="booking-email-"]', 'ada@example.com')
        ->press('Next')
        ->assertSee('Confirm your booking')
        ->wait(3)
        ->press('Book now')
        ->assertSee('Change or cancel this booking');

    $booking = Booking::query()->sole();

    expect($booking->status)->toBe(BookingStatus::CONFIRMED)
        ->and($booking->timezone)->toBe('Asia/Tokyo')
        ->and($booking->starts_at->utc()->format('Y-m-d\TH:i:s\Z'))->toBe($slot);

    $browser->navigate($browser->script("document.querySelector('[data-booking-done] a').href"))
        ->assertSee('Your booking')
        ->press('Move to another time')
        ->assertSee('Pick a new time');

    $newSlot = $browser->script("[...document.querySelectorAll('[data-slot]')].map(el => el.dataset.slot).find(value => value !== '{$slot}')");

    expect($newSlot)->toBeString();

    $browser->click("[data-slot=\"{$newSlot}\"]")->press('Move to this time');

    assertScriptEventually($browser, "document.querySelector('[data-booking-notice]') !== null", true);

    $browser->assertNoJavaScriptErrors();

    expect($booking->refresh()->starts_at->utc()->format('Y-m-d\TH:i:s\Z'))->toBe($newSlot)
        ->and($booking->sequence)->toBe(1);
});
