<?php

declare(strict_types=1);

use App\Actions\ConfirmBookingPaymentAction;
use App\Actions\CreateBookingAction;
use App\Actions\FulfillCheckoutAction;
use App\Actions\ReleaseOrderAction;
use App\Actions\StartBookingCheckoutAction;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Models\Booking;
use App\Models\Order;
use App\Notifications\BookingAlert;
use App\Notifications\BookingUpdate;
use App\Notifications\OrderConfirmation;
use App\Notifications\OrderReceived;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Stripe\Exception\InvalidRequestException;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00', 'UTC'));
    openBookingHours();
    connectStripe();
});

function heldBooking(string $session = 'cs_hold'): Booking
{
    $service = bookableService(['current_price' => '50', 'charge_when_booking' => true]);
    $booking = resolve(CreateBookingAction::class)->handle($service, CarbonImmutable::parse('2026-11-18 10:00', 'UTC'), ['name' => 'Ada', 'email' => 'ada@example.com']);
    $booking->order?->update(['stripe_session_id' => $session]);

    return $booking->refresh();
}

it('confirms the booking instead of sending order emails once Stripe says it is paid', function (): void {
    Notification::fake();
    $booking = heldBooking();
    stripe()->respond('GET', '/v1/checkout/sessions/cs_hold', stripeSession('cs_hold'));

    stripeWebhook('checkout.session.completed', ['id' => 'cs_hold'])->assertOk();

    expect($booking->refresh()->status)->toBe(BookingStatus::CONFIRMED)
        ->and($booking->order?->status)->toBe(OrderStatus::PAID)
        ->and(Order::awaitingFulfilmentCount())->toBe(0);

    Notification::assertSentOnDemandTimes(BookingUpdate::class, 1);
    Notification::assertSentOnDemandTimes(BookingAlert::class, 1);
    Notification::assertSentOnDemandTimes(OrderConfirmation::class, 0);
    Notification::assertSentOnDemandTimes(OrderReceived::class, 0);

    resolve(FulfillCheckoutAction::class)->handle('cs_hold');

    Notification::assertSentOnDemandTimes(BookingUpdate::class, 1);
});

it('leaves a booking that was cancelled before its payment arrived as it is', function (): void {
    $booking = heldBooking();
    $booking->update(['status' => BookingStatus::CANCELLED]);
    Notification::fake();

    expect(resolve(ConfirmBookingPaymentAction::class)->handle(Order::query()->findOrFail($booking->order_id)))->toBeTrue()
        ->and($booking->refresh()->status)->toBe(BookingStatus::CANCELLED);

    Notification::assertNothingSent();
});

it('frees the time when the checkout expires unpaid', function (): void {
    $booking = heldBooking();

    stripeWebhook('checkout.session.expired', ['id' => 'cs_hold', 'metadata' => ['order_id' => (string) $booking->order_id]])->assertOk();

    expect($booking->refresh()->status)->toBe(BookingStatus::EXPIRED)
        ->and($booking->claims()->count())->toBe(0);
});

it('takes the time back when a late payment arrives and it is still free', function (): void {
    Notification::fake();
    $booking = heldBooking();
    resolve(ReleaseOrderAction::class)->handle($booking->order);
    stripe()->respond('GET', '/v1/checkout/sessions/cs_hold', stripeSession('cs_hold'));

    resolve(FulfillCheckoutAction::class)->handle('cs_hold');

    expect($booking->refresh()->status)->toBe(BookingStatus::CONFIRMED)
        ->and($booking->clashed)->toBeFalse()
        ->and($booking->claims()->count())->toBe(12);

    Notification::assertSentOnDemand(BookingUpdate::class);
});

it('flags a late payment whose time was booked by someone else', function (): void {
    Notification::fake();
    $booking = heldBooking();
    resolve(ReleaseOrderAction::class)->handle($booking->order);
    Booking::factory()->claimed()->create(['starts_at' => CarbonImmutable::parse('2026-11-18 10:00', 'UTC')]);
    stripe()->respond('GET', '/v1/checkout/sessions/cs_hold', stripeSession('cs_hold'));

    resolve(FulfillCheckoutAction::class)->handle('cs_hold');

    expect($booking->refresh()->status)->toBe(BookingStatus::CONFIRMED)
        ->and($booking->clashed)->toBeTrue()
        ->and($booking->claims()->count())->toBe(0);

    Notification::assertSentOnDemandTimes(BookingUpdate::class, 0);
    Notification::assertSentOnDemand(BookingAlert::class, fn (BookingAlert $notification): bool => $notification->kind === BookingAlert::CLASHED);
});

it('lets go of the time when Stripe cannot start the checkout', function (): void {
    $booking = heldBooking();
    stripe()->fail('POST', '/v1/checkout/sessions', 'Stripe is down');

    expect(fn () => resolve(StartBookingCheckoutAction::class)->handle($booking))->toThrow(InvalidRequestException::class)
        ->and($booking->refresh()->status)->toBe(BookingStatus::EXPIRED);
});

it('frees unpaid holds even while Stripe is disconnected', function (): void {
    $booking = heldBooking();
    config(['cashier.secret' => null]);

    $this->travel(41)->minutes();
    Artisan::call('wireup:send-booking-reminders');

    expect($booking->refresh()->status)->toBe(BookingStatus::EXPIRED);
});

it('keeps an order that is not a booking on the normal path', function (): void {
    $order = Order::factory()->create();

    expect(resolve(ConfirmBookingPaymentAction::class)->handle($order))->toBeFalse();
});
