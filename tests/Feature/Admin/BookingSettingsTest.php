<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\Settings;
use App\Models\User;
use App\Services\BookingService;
use Livewire\Livewire;

it('gives availability its own settings section once a content type takes bookings', function (): void {
    $this->actingAsAdmin()->get(route('admin.settings-general'))->assertDontSee(route('admin.settings-bookings'), false);

    bookableService();

    $this->get(route('admin.settings-general'))->assertSee(route('admin.settings-bookings'), false);
    $this->get(route('admin.settings-bookings'))->assertOk()->assertSee('Availability')->assertSee('Weekly hours')->assertSee('Make a calendar link');
    $this->get(route('admin.bookings-index'))->assertSee(route('admin.settings-bookings'), false);
});

it('saves the hours, rules and closed days', function (): void {
    $this->actingAsAdmin();

    Livewire::test('pages::admin.settings-bookings')
        ->assertSet('hours.1', [['start' => '09:00', 'end' => '17:00']])
        ->assertSet('hours.6', [])
        ->set('timezone', 'Europe/Lisbon')
        ->call('addRange', 6)
        ->set('hours.6.0.start', '10:00')
        ->set('hours.6.0.end', '24:00')
        ->call('removeRange', 5, 0)
        ->set('interval', 15)
        ->set('buffer', 10)
        ->set('notice', 12)
        ->set('daysAhead', 90)
        ->set('cutoff', 48)
        ->set('reminder', 0)
        ->call('addClosed')
        ->set('closed.0.from', '2026-12-24')
        ->set('closed.0.to', '2026-12-26')
        ->call('addClosed')
        ->call('removeClosed', 1)
        ->call('update')
        ->assertHasNoErrors();

    $bookings = resolve(BookingService::class);

    expect($bookings->timezone())->toBe('Europe/Lisbon')
        ->and($bookings->hours())->not->toHaveKey(5)
        ->and($bookings->hours()[6])->toBe([['start' => '10:00', 'end' => '24:00']])
        ->and($bookings->interval())->toBe(15)
        ->and($bookings->buffer())->toBe(10)
        ->and($bookings->noticeMinutes())->toBe(720)
        ->and($bookings->daysAhead())->toBe(90)
        ->and($bookings->cancelCutoffHours())->toBe(48)
        ->and($bookings->reminderHours())->toBe(0)
        ->and($bookings->closedRanges())->toBe([['from' => '2026-12-24', 'to' => '2026-12-26']]);
});

it('explains hours and closed days that do not work', function (): void {
    $this->actingAsAdmin();

    Livewire::test('pages::admin.settings-bookings')
        ->set('hours.1.0.start', '09:07')
        ->call('update')
        ->assertHasErrors('hours.1.0.start')
        ->assertSee('Use a start time on a 5-minute step')
        ->set('hours.1.0.start', '18:00')
        ->call('update')
        ->assertHasErrors('hours.1.0.end')
        ->assertSee('The end time must be after the start time.')
        ->set('hours.1.0.start', '09:00')
        ->call('addClosed')
        ->set('closed.0.from', '2026-12-26')
        ->set('closed.0.to', '2026-12-24')
        ->call('update')
        ->assertHasErrors('closed.0.to')
        ->assertSee('The last closed day can’t be before the first.');
});

it('makes a private calendar link and can replace it', function (): void {
    $this->actingAsAdmin();

    $page = Livewire::test('pages::admin.settings-bookings')->call('newFeedLink');
    $first = Settings::get('booking_feed_token');

    $page->call('newFeedLink');

    expect($first)->toHaveLength(48)
        ->and(Settings::get('booking_feed_token'))->not->toBe($first)
        ->and($page->instance()->feedUrl())->toBe(route('booking.feed', Settings::get('booking_feed_token')));
});

it('only lets settings editors change booking settings', function (): void {
    $role = Role::query()->create(['key' => 'settings-viewer', 'name' => 'Viewer', 'abilities' => ['settings.view'], 'bypass' => false, 'is_protected' => false]);
    $this->actingAs(User::factory()->role($role)->create(['active' => true]));

    Livewire::test('pages::admin.settings-bookings')->call('update')->assertForbidden();
    Livewire::test('pages::admin.settings-bookings')->call('newFeedLink')->assertForbidden();
});
