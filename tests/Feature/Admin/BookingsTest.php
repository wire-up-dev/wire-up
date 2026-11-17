<?php

declare(strict_types=1);

use App\Enums\ActivityEvent;
use App\Enums\BookingActor;
use App\Enums\BookingStatus;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Notifications\BookingUpdate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00', 'UTC'));
    openBookingHours();
});

function bookingsViewer(): User
{
    $role = Role::query()->create(['key' => 'bookings-viewer', 'name' => 'Viewer', 'abilities' => ['bookings.view'], 'bypass' => false, 'is_protected' => false]);

    return User::factory()->role($role)->create(['active' => true]);
}

it('lists upcoming bookings first and past ones on their own tab', function (): void {
    bookableService();
    Booking::factory()->create(['name' => 'Next Week', 'starts_at' => CarbonImmutable::parse('2026-11-23 10:00', 'UTC')]);
    Booking::factory()->create(['name' => 'Tomorrow', 'starts_at' => CarbonImmutable::parse('2026-11-17 10:00', 'UTC'), 'clashed' => true]);
    Booking::factory()->create(['name' => 'Last Month', 'starts_at' => CarbonImmutable::parse('2026-10-10 10:00', 'UTC')]);
    Booking::factory()->cancelled()->create(['name' => 'Called Off', 'starts_at' => CarbonImmutable::parse('2026-11-25 10:00', 'UTC')]);

    $this->actingAsAdmin()->get(route('admin.bookings-index'))->assertOk()->assertSeeInOrder(['Tomorrow', 'Next Week'])->assertSee('Clash')->assertDontSee('Last Month');

    Livewire::test('pages::admin.bookings-index')
        ->set('status', 'cancelled')->assertSee('Called Off')->assertDontSee('Tomorrow')
        ->set('status', '')->set('search', 'next')->assertSee('Next Week')->assertDontSee('Tomorrow')
        ->set('search', '')->set('when', 'past')->assertSee('Last Month')->assertDontSee('Next Week')
        ->set('search', 'nobody')->assertSee('No past bookings yet.')
        ->set('status', 'confirmed')
        ->call('clearFilters')
        ->assertSet('when', 'upcoming')
        ->assertSet('status', '');
});

it('shows bookings in the sidebar once a content type takes bookings', function (): void {
    $this->actingAsAdmin()->get(route('admin.dashboard'))->assertDontSee(route('admin.bookings-index'), false);

    bookableService();

    $this->get(route('admin.dashboard'))->assertSee(route('admin.bookings-index'), false);
});

it('adds a booking or blocks time from the admin, without overlapping', function (): void {
    Notification::fake();
    $service = bookableService();
    $this->actingAsAdmin();

    Livewire::test('pages::admin.bookings-index')
        ->call('openAdd')
        ->set('serviceId', (string) $service->id)
        ->set('day', '2026-11-18')
        ->set('time', '19:00')
        ->set('name', 'Phone Caller')
        ->set('email', 'caller@example.com')
        ->call('add')
        ->assertHasNoErrors()
        ->call('openAdd')
        ->set('day', '2026-11-18')
        ->set('time', '19:30')
        ->set('duration', 30)
        ->call('add')
        ->assertHasErrors('time')
        ->set('time', '7pm')
        ->call('add')
        ->assertHasErrors(['time' => 'date_format'])
        ->set('time', '21:00')
        ->set('duration', 33)
        ->call('add')
        ->assertHasErrors(['duration' => 'multiple_of'])
        ->set('duration', 120)
        ->call('add')
        ->assertHasNoErrors();

    $bookings = Booking::query()->oldest('starts_at')->get();

    expect($bookings)->toHaveCount(2)
        ->and($bookings[0]->source)->toBe(BookingActor::OWNER)
        ->and($bookings[0]->service_name)->toBe('Coaching call')
        ->and($bookings[1]->service_name)->toBe('Busy')
        ->and($bookings[1]->duration_minutes)->toBe(120)
        ->and(Activity::query()->where('event', ActivityEvent::BOOKING_CREATED)->count())->toBe(2);

    Notification::assertSentOnDemandTimes(BookingUpdate::class, 1);
});

it('cancels and moves a booking from its page', function (): void {
    Notification::fake();
    $booking = Booking::factory()->claimed()->create(['starts_at' => CarbonImmutable::parse('2026-11-18 10:00', 'UTC'), 'timezone' => 'Asia/Tokyo', 'notes' => 'Bring notes', 'phone' => '555']);
    $this->actingAsAdmin();

    $this->get(route('admin.bookings-show', $booking))->assertOk()->assertSee('Bring notes')->assertSee('Their time')->assertSee('555');

    Livewire::test('pages::admin.bookings-show', ['booking' => $booking])
        ->call('openReschedule')
        ->assertSet('day', '2026-11-18')
        ->assertSet('time', '10:00')
        ->set('time', '6am')
        ->call('reschedule')
        ->assertHasErrors('time')
        ->set('time', '21:00')
        ->call('reschedule')
        ->assertHasNoErrors()
        ->call('cancel')
        ->assertSee('Cancelled');

    $booking->refresh();

    expect($booking->status)->toBe(BookingStatus::CANCELLED)
        ->and($booking->cancelled_by)->toBe(BookingActor::OWNER)
        ->and($booking->starts_at->format('H:i'))->toBe('21:00')
        ->and(Activity::query()->pluck('event')->map->value->all())->toContain('booking.rescheduled', 'booking.cancelled');

    Livewire::test('pages::admin.bookings-show', ['booking' => $booking])->call('cancel');

    expect(Activity::query()->where('event', ActivityEvent::BOOKING_CANCELLED)->count())->toBe(1);
});

it('refuses to move a booking onto taken time', function (): void {
    $booking = Booking::factory()->claimed()->create(['starts_at' => CarbonImmutable::parse('2026-11-18 10:00', 'UTC')]);
    Booking::factory()->claimed()->create(['starts_at' => CarbonImmutable::parse('2026-11-18 12:00', 'UTC')]);
    $this->actingAsAdmin();

    Livewire::test('pages::admin.bookings-show', ['booking' => $booking])
        ->call('openReschedule')
        ->set('time', '12:30')
        ->call('reschedule')
        ->assertHasErrors('time');

    expect($booking->refresh()->starts_at->format('H:i'))->toBe('10:00');
});

it('links a paid booking to its order and shows a clash', function (): void {
    $order = Order::factory()->paid()->create();
    $booking = Booking::factory()->create(['order_id' => $order->id, 'clashed' => true, 'name' => null, 'email' => null]);

    $this->actingAsAdmin()->get(route('admin.bookings-show', $booking))
        ->assertSee($order->reference)
        ->assertSee(route('admin.orders-show', $order), false)
        ->assertSee('This booking clashes with another one')
        ->assertSee('Blocked time');
});

it('lets viewers look but not change anything', function (): void {
    $booking = Booking::factory()->claimed()->create();
    $this->actingAs(bookingsViewer());

    $this->get(route('admin.bookings-show', $booking))->assertOk()->assertDontSee('Cancel booking');

    Livewire::test('pages::admin.bookings-index')->call('openAdd')->assertForbidden();
    Livewire::test('pages::admin.bookings-show', ['booking' => $booking])->call('cancel')->assertForbidden();
    Livewire::test('pages::admin.bookings-show', ['booking' => $booking])->call('openReschedule')->assertForbidden();
    Livewire::test('pages::admin.bookings-show', ['booking' => $booking])->call('reschedule')->assertForbidden();
    Livewire::test('pages::admin.bookings-index')->call('add')->assertForbidden();
    Livewire::test('pages::admin.bookings-index')->call('cancel', $booking->id)->assertForbidden();
    Livewire::withQueryParams(['move' => 1])->test('pages::admin.bookings-show', ['booking' => $booking])->assertSet('showMove', false);

    expect($booking->refresh()->status)->toBe(BookingStatus::CONFIRMED);
});

it('cancels a booking or opens its move form from the row menu', function (): void {
    Notification::fake();
    $booking = Booking::factory()->claimed()->create(['name' => 'Row Person']);
    $cancelled = Booking::factory()->cancelled()->create();
    $this->actingAsAdmin();

    $this->get(route('admin.bookings-index'))
        ->assertSee(route('admin.bookings-show', ['booking' => $booking, 'move' => 1]), false);

    Livewire::withQueryParams(['move' => 1])->test('pages::admin.bookings-show', ['booking' => $booking])
        ->assertSet('showMove', true)
        ->assertSet('time', $booking->starts_at->format('H:i'));

    Livewire::withQueryParams(['move' => 1])->test('pages::admin.bookings-show', ['booking' => $cancelled])->assertSet('showMove', false);

    Livewire::test('pages::admin.bookings-index')
        ->call('cancel', $booking->id)
        ->call('cancel', $booking->id)
        ->call('cancel', 999999);

    expect($booking->refresh()->status)->toBe(BookingStatus::CANCELLED)
        ->and(Activity::query()->where('event', ActivityEvent::BOOKING_CANCELLED)->count())->toBe(1);
});
