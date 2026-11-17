<?php

declare(strict_types=1);

use App\Enums\BookingActor;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Settings;
use App\Notifications\BookingAlert;
use App\Notifications\BookingUpdate;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00', 'UTC'));
    openBookingHours(['booking_cancel_cutoff_hours' => 24]);
    Settings::set(['contact_email' => 'owner@example.com']);
});

function confirmedBooking(string $start = '2026-11-20 10:00', array $attributes = []): Booking
{
    $service = bookableService();

    return Booking::factory()->claimed()->create([
        'record_id' => $service->id,
        'starts_at' => CarbonImmutable::parse($start, 'UTC'),
        'timezone' => 'Europe/Amsterdam',
        ...$attributes,
    ]);
}

it('shows the booking to whoever has the link, and nothing for a wrong one', function (): void {
    $booking = confirmedBooking();

    $this->get(route('booking.manage', $booking->manage_token))
        ->assertOk()
        ->assertSee('Coaching session')
        ->assertSee('Friday 20 November 2026, 11:00 AM')
        ->assertSee('Europe/Amsterdam')
        ->assertSee('Move to another time');

    $this->get(route('booking.manage', str_repeat('x', 48)))->assertNotFound();
});

it('lets the visitor cancel before the cutoff and tells the owner', function (): void {
    Notification::fake();
    $booking = confirmedBooking();

    Livewire::test('pages::booking-manage', ['token' => $booking->manage_token])
        ->call('cancel')
        ->assertSee('Your booking is cancelled.');

    $booking->refresh();

    expect($booking->status)->toBe(BookingStatus::CANCELLED)
        ->and($booking->cancelled_by)->toBe(BookingActor::CUSTOMER)
        ->and($booking->sequence)->toBe(1)
        ->and($booking->claims()->count())->toBe(0);

    Notification::assertSentOnDemand(BookingUpdate::class, fn (BookingUpdate $notification): bool => $notification->kind === BookingUpdate::CANCELLED);
    Notification::assertSentOnDemand(BookingAlert::class, fn (BookingAlert $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notification->kind === BookingAlert::CANCELLED && $notifiable->routes['mail'] === 'owner@example.com');
});

it('lets the visitor move the booking to another free time', function (): void {
    Notification::fake();
    $booking = confirmedBooking();

    $page = Livewire::test('pages::booking-manage', ['token' => $booking->manage_token])
        ->call('reschedule')
        ->assertHasErrors('time')
        ->call('startRescheduling')
        ->assertSet('rescheduling', true)
        ->call('pickDate', '2026-11-19')
        ->call('pickSlot', '2026-11-19T14:00:00Z')
        ->call('reschedule')
        ->assertHasNoErrors()
        ->assertSee('Your booking has moved.');

    $booking->refresh();

    expect($booking->starts_at->format('Y-m-d H:i'))->toBe('2026-11-19 14:00')
        ->and($booking->sequence)->toBe(1)
        ->and($booking->claims()->min('starts_at'))->toStartWith('2026-11-19 14:00');

    Notification::assertSentOnDemand(BookingUpdate::class, fn (BookingUpdate $notification): bool => $notification->kind === BookingUpdate::RESCHEDULED);
    Notification::assertSentOnDemand(BookingAlert::class, fn (BookingAlert $notification): bool => $notification->kind === BookingAlert::RESCHEDULED);
});

it('keeps the old time when the new one was taken meanwhile', function (): void {
    $booking = confirmedBooking();
    $page = Livewire::test('pages::booking-manage', ['token' => $booking->manage_token])
        ->call('startRescheduling')
        ->call('pickDate', '2026-11-19')
        ->call('pickSlot', '2026-11-19T14:00:00Z');

    Booking::factory()->claimed()->create(['starts_at' => CarbonImmutable::parse('2026-11-19 14:00', 'UTC')]);

    $page->call('reschedule')->assertHasErrors('time');

    expect($booking->refresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-11-20 10:00')
        ->and($booking->claims()->count())->toBe(12);
});

it('refuses changes once the booking is too close or already over', function (): void {
    $soon = confirmedBooking('2026-11-16 20:00');

    $this->get(route('booking.manage', $soon->manage_token))
        ->assertSee('This booking is too close to change online.')
        ->assertDontSee('Move to another time');

    Livewire::test('pages::booking-manage', ['token' => $soon->manage_token])
        ->call('cancel')
        ->call('startRescheduling')
        ->assertSet('rescheduling', false);

    expect($soon->refresh()->status)->toBe(BookingStatus::CONFIRMED);

    $cancelled = Booking::factory()->cancelled()->create();

    $this->get(route('booking.manage', $cancelled->manage_token))->assertSee('This booking is cancelled.');
});

it('confirms a paid booking when the visitor comes back from Stripe', function (): void {
    Notification::fake();
    connectStripe();
    $order = Order::factory()->create(['stripe_session_id' => 'cs_paid', 'email' => 'ada@example.com']);
    $booking = confirmedBooking(attributes: ['status' => BookingStatus::PENDING_PAYMENT, 'order_id' => $order->id, 'confirmed_at' => null]);
    stripe()->respond('GET', '/v1/checkout/sessions/cs_paid', stripeSession('cs_paid'));

    $this->get(route('booking.manage', ['token' => $booking->manage_token, 'session_id' => 'cs_paid']))->assertOk()->assertSee('Confirmed');

    expect($booking->refresh()->status)->toBe(BookingStatus::CONFIRMED)
        ->and($order->refresh()->status)->toBe(OrderStatus::PAID);

    Notification::assertSentOnDemand(BookingUpdate::class, fn (BookingUpdate $notification): bool => $notification->kind === BookingUpdate::CONFIRMED);
    Notification::assertSentOnDemandTimes(BookingAlert::class, 1);
});

it('frees the time when the visitor backs out of paying', function (): void {
    connectStripe();
    $order = Order::factory()->create(['stripe_session_id' => 'cs_backed_out']);
    $booking = confirmedBooking(attributes: ['status' => BookingStatus::PENDING_PAYMENT, 'order_id' => $order->id, 'confirmed_at' => null]);
    stripe()->respond('POST', '/v1/checkout/sessions/cs_backed_out/expire', ['id' => 'cs_backed_out', 'status' => 'expired']);

    $this->get(route('booking.manage', ['token' => $booking->manage_token, 'cancelled' => '1']))
        ->assertOk()
        ->assertSee('This booking was not paid, so the time was released.');

    expect($booking->refresh()->status)->toBe(BookingStatus::EXPIRED)
        ->and($booking->claims()->count())->toBe(0)
        ->and($order->refresh()->status)->toBe(OrderStatus::CANCELLED);
});

it('says a payment is still on its way', function (): void {
    $booking = confirmedBooking(attributes: ['status' => BookingStatus::PENDING_PAYMENT, 'confirmed_at' => null]);

    $this->get(route('booking.manage', $booking->manage_token))->assertSee('We are waiting for your payment to go through.');
});
