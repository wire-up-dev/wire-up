<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Models\Booking;
use App\Models\Locale;
use App\Models\Record;
use App\Models\RecordType;
use App\Models\Settings;
use App\Services\BookingAvailability;
use App\Services\BookingService;
use App\Services\RecordFieldText;
use Carbon\CarbonImmutable;

mutates(BookingAvailability::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00', 'UTC'));
});

it('starts from sensible defaults', function (): void {
    $bookings = resolve(BookingService::class);

    expect($bookings->timezone())->toBe('UTC')
        ->and($bookings->hours())->toBe(BookingService::DEFAULT_HOURS)
        ->and($bookings->interval())->toBe(30)
        ->and($bookings->buffer())->toBe(0)
        ->and($bookings->noticeMinutes())->toBe(1440)
        ->and($bookings->daysAhead())->toBe(60)
        ->and($bookings->cancelCutoffHours())->toBe(24)
        ->and($bookings->reminderHours())->toBe(24)
        ->and($bookings->closedRanges())->toBeEmpty()
        ->and($bookings->feedToken())->toBeEmpty()
        ->and($bookings->isOpen())->toBeFalse();
});

it('ignores saved settings that make no sense', function (): void {
    Settings::set([
        'booking_timezone' => 'Mars/Olympus',
        'booking_hours' => [
            '1' => [['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '24:00']],
            2 => [['start' => '12:00', 'end' => '09:00'], ['start' => '09:03', 'end' => '10:00'], 'nonsense'],
            3 => 'closed',
        ],
        'booking_interval' => 7,
        'booking_buffer' => 1000,
        'booking_notice_hours' => 'soon',
        'booking_closed' => [['from' => '2026-12-24', 'to' => '2026-12-26'], ['from' => '2026-12-31', 'to' => '2026-12-01'], ['from' => '2026-02-30'], 'x'],
    ]);

    $bookings = resolve(BookingService::class);

    expect($bookings->timezone())->toBe('UTC')
        ->and($bookings->hours())->toBe([1 => [['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '24:00']]])
        ->and($bookings->interval())->toBe(30)
        ->and($bookings->buffer())->toBe(0)
        ->and($bookings->noticeMinutes())->toBe(1440)
        ->and($bookings->closedRanges())->toBe([['from' => '2026-12-24', 'to' => '2026-12-26'], ['from' => '2026-12-31', 'to' => '2026-12-31']]);

    Settings::set(['booking_hours' => 'nope']);

    expect(resolve(BookingService::class)->hours())->toBe(BookingService::DEFAULT_HOURS);
});

it('reads a service length, price, payment and place from the record', function (): void {
    $bookings = resolve(BookingService::class);
    $service = bookableService(['current_price' => '80', 'charge_when_booking' => true, 'booking_location' => ['en' => 'Zoom', 'nl' => 'Online']]);

    expect($bookings->duration($service))->toBe(60)
        ->and($bookings->price($service))->toBe('80')
        ->and($bookings->chargesOnBooking($service))->toBeFalse()
        ->and($bookings->location($service))->toBe('Zoom')
        ->and($bookings->location($service, 'nl'))->toBe('Online')
        ->and($bookings->location($service, 'fr'))->toBe('Zoom');

    connectStripe();

    expect($bookings->chargesOnBooking($service))->toBeTrue()
        ->and($bookings->isBookable($service))->toBeTrue();

    foreach ([['duration_minutes' => 'long'], ['duration_minutes' => 7], ['duration_minutes' => 900]] as $broken) {
        expect($bookings->duration(bookableService($broken)))->toBe(0);
    }

    $plain = bookableService(['current_price' => '0', 'booking_location' => 12]);

    expect($bookings->price($plain))->toBeNull()
        ->and($bookings->chargesOnBooking($plain))->toBeFalse()
        ->and($bookings->location($plain))->toBeEmpty();
});

it('offers only published, bookable services, in the order chosen', function (): void {
    $first = bookableService(['heading' => ['en' => 'First']]);
    $second = bookableService(['heading' => ['en' => 'Second']]);
    bookableService(['duration_minutes' => 0]);
    Record::factory()->for(RecordType::query()->where('bookable', true)->sole(), 'recordType')->create(['status' => ContentStatus::DRAFT, 'data' => ['duration_minutes' => 60]]);
    sellableRecord();

    $bookings = resolve(BookingService::class);

    expect($bookings->services()->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($bookings->services([(string) $second->id, $first->id])->pluck('id')->all())->toBe([$second->id, $first->id])
        ->and($bookings->isOpen())->toBeTrue();
});

it('leaves taken time out of the free slots, except for the booking being moved', function (): void {
    openBookingHours();
    $booking = Booking::factory()->claimed()->create(['starts_at' => CarbonImmutable::parse('2026-11-17 10:00', 'UTC')]);
    $availability = resolve(BookingAvailability::class);

    $times = fn (?int $ignore = null): array => array_map(fn (CarbonImmutable $slot): string => $slot->format('H:i'), $availability->slots(60, '2026-11-17', '2026-11-17', $ignore));

    expect($times())->not->toContain('10:00')
        ->and($times())->toContain('09:00', '11:00')
        ->and($times($booking->id))->toContain('10:00')
        ->and($availability->slots(0, '2026-11-17', '2026-11-17'))->toBeEmpty()
        ->and($availability->isOffered(60, CarbonImmutable::parse('2026-11-17 11:00', 'UTC')))->toBeTrue()
        ->and($availability->isOffered(60, CarbonImmutable::parse('2026-11-17 10:00', 'UTC')))->toBeFalse()
        ->and($availability->isOffered(60, CarbonImmutable::parse('2026-11-17 08:00', 'UTC'), minimumNotice: 60 * 24 * 7))->toBeFalse();
});

it('writes times in the reader\'s zone and language and links to the right language', function (): void {
    Locale::query()->where('code', 'nl')->update(['active' => true]);
    cache()->forget('site-locales');
    $bookings = resolve(BookingService::class);
    $booking = Booking::factory()->create(['locale' => 'nl', 'starts_at' => CarbonImmutable::parse('2026-11-20 15:00', 'UTC')]);

    expect($bookings->formatTime($booking->starts_at, 'Europe/Amsterdam', 'nl'))->toBe('vrijdag 20 november 2026, 16:00')
        ->and($bookings->formatDay($booking->starts_at, 'America/New_York', 'en'))->toBe('Friday 20 November 2026')
        ->and($bookings->formatClock($booking->starts_at, 'America/New_York', 'en'))->toBe('10:00 AM')
        ->and($bookings->manageUrl($booking))->toEndWith('/nl/booking/manage/'.$booking->manage_token)
        ->and($bookings->manageUrl($booking, ['cancelled' => '1']))->toEndWith('?cancelled=1')
        ->and($bookings->serviceLocation($booking))->toBeEmpty();
});

it('shows a service length in minutes in the admin list and on its page', function (): void {
    $service = bookableService(['duration_minutes' => 45]);
    $plain = RecordType::factory()->create(['fields' => [['key' => 'duration_minutes', 'type' => 'number', 'label' => ['en' => 'Pages'], 'column' => true]]]);
    $book = Record::factory()->for($plain, 'recordType')->create(['data' => ['duration_minutes' => 300]]);

    expect(RecordFieldText::column($service, ['key' => 'duration_minutes', 'type' => 'number']))->toBe('45 min')
        ->and(RecordFieldText::column($book, ['key' => 'duration_minutes', 'type' => 'number']))->toBe('300');

    $this->actingAsAdmin()
        ->get(route('admin.records-index', $service->recordType))
        ->assertSee('Length')
        ->assertSee('45 min');

    $this->get(route('record', [$service->recordType->slug_prefix, 'service-'.$service->id]))
        ->assertOk()
        ->assertSee('45 min');
});
