<?php

declare(strict_types=1);

use App\Enums\BlockType;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Settings;
use App\Models\User;
use App\Notifications\BookingAlert;
use App\Notifications\BookingUpdate;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00', 'UTC'));
    openBookingHours();
    Settings::set(['contact_email' => 'owner@example.com']);
});

/**
 * @param  array<string, mixed>  $config
 */
function bookingWidget(array $config = []): Testable
{
    return Livewire::test('site.booking', ['config' => [...BlockType::BOOKING->defaultContent(), ...$config], 'blockId' => null]);
}

function filledBooking(Testable $widget, string $slot = '2026-11-17T10:00:00Z'): Testable
{
    test()->travel(5)->seconds();

    return $widget->call('pickDate', '2026-11-17')
        ->call('pickSlot', $slot)
        ->call('toDetails')
        ->set('name', 'Ada Lovelace')
        ->set('email', 'Ada@Example.com')
        ->set('notes', 'First session');
}

it('lets a visitor pick a service, a day and a time, then books it', function (): void {
    Notification::fake();
    $intro = bookableService(['heading' => ['en' => 'Intro call'], 'duration_minutes' => 30]);
    bookableService(['heading' => ['en' => 'Deep dive'], 'current_price' => '120']);

    $widget = bookingWidget()
        ->assertSet('step', 'service')
        ->assertSee('Intro call')
        ->assertSee('Deep dive')
        ->assertSee('$120.00')
        ->call('chooseService', $intro->id)
        ->assertSet('step', 'time')
        ->assertSeeHtml('data-available-day="2026-11-17"')
        ->call('toDetails')
        ->assertHasErrors('time');

    filledBooking($widget)
        ->assertSet('step', 'details')
        ->call('book')
        ->assertHasNoErrors()
        ->assertSet('step', 'done')
        ->assertSee('You are booked in.');

    $booking = Booking::query()->sole();

    expect($booking->status)->toBe(BookingStatus::CONFIRMED)
        ->and($booking->service_name)->toBe('Intro call')
        ->and($booking->starts_at->format('Y-m-d H:i'))->toBe('2026-11-17 10:00')
        ->and($booking->ends_at->format('H:i'))->toBe('10:30')
        ->and($booking->email)->toBe('ada@example.com')
        ->and($booking->notes)->toBe('First session')
        ->and($booking->phone)->toBeNull()
        ->and($booking->claims()->count())->toBe(6)
        ->and($booking->reminded_at)->toBeNull();

    Notification::assertSentOnDemand(BookingUpdate::class, fn (BookingUpdate $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'ada@example.com' && $notification->kind === BookingUpdate::CONFIRMED && $notification->locale === 'en');
    Notification::assertSentOnDemand(BookingAlert::class, fn (BookingAlert $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'owner@example.com' && $notification->kind === BookingAlert::NEW);
});

it('skips the service step when there is only one and fills in a signed-in member', function (): void {
    $service = bookableService();
    $member = User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com', 'active' => true]);
    $this->actingAs($member);

    $widget = bookingWidget(['recordIds' => [$service->id]])
        ->assertSet('step', 'time')
        ->assertSet('name', 'Grace Hopper')
        ->assertSet('email', 'grace@example.com');

    test()->travel(5)->seconds();

    $widget->call('pickDate', '2026-11-17')->call('pickSlot', '2026-11-17T09:00:00Z')->call('toDetails')->call('book')->assertSet('step', 'done');

    expect(Booking::query()->sole()->user_id)->toBe($member->id);
});

it('sends the visitor to Stripe when the service is paid when booking', function (): void {
    connectStripe();
    stripe()->respond('POST', '/v1/checkout/sessions', ['id' => 'cs_booking', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_booking']);
    bookableService(['current_price' => '80', 'charge_when_booking' => true]);

    filledBooking(bookingWidget())
        ->assertSee('Continue to payment')
        ->call('book')
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_booking');

    $booking = Booking::query()->sole();
    $order = Order::query()->sole();

    expect($booking->status)->toBe(BookingStatus::PENDING_PAYMENT)
        ->and($booking->order_id)->toBe($order->id)
        ->and($booking->claims()->count())->toBe(12)
        ->and($order->total_amount)->toBe(8000)
        ->and($order->stripe_session_id)->toBe('cs_booking')
        ->and($order->items->sole()->name)->toBe('Coaching call');

    stripe()->assertSent('POST', '/v1/checkout/sessions', fn (array $params): bool => $params['payment_method_types'] === ['card']
        && $params['line_items'][0]['price_data']['unit_amount'] === 8000
        && str_contains($params['success_url'], '/booking/manage/'.$booking->manage_token.'?session_id=')
        && str_ends_with($params['cancel_url'], '?cancelled=1')
        && $params['metadata']['booking_reference'] === $booking->reference
        && $params['customer_email'] === 'ada@example.com');
});

it('sends the visitor back to pick again when the time was just taken', function (): void {
    bookableService();
    $widget = filledBooking(bookingWidget());
    Booking::factory()->claimed()->create(['starts_at' => CarbonImmutable::parse('2026-11-17 10:00', 'UTC')]);

    $widget->call('book')
        ->assertSet('step', 'time')
        ->assertSet('slot', '')
        ->assertHasErrors('time');

    expect(Booking::query()->count())->toBe(1);
});

it('asks for a phone number only when the block wants one', function (): void {
    bookableService();

    filledBooking(bookingWidget(['phone' => 'required']))->call('book')->assertHasErrors(['phone' => 'required']);
    filledBooking(bookingWidget(['phone' => 'off']))->set('phone', '555')->call('book')->assertHasNoErrors();
    filledBooking(bookingWidget(['phone' => 'weird']), '2026-11-17T11:00:00Z')->assertSee('Phone (optional)')->set('phone', '555 0100')->call('book')->assertHasNoErrors();

    expect(Booking::query()->orderBy('id')->pluck('phone')->all())->toBe([null, '555 0100']);
});

it('refuses bots, rushed submissions, staff acting as a member and repeat attempts', function (): void {
    bookableService();

    filledBooking(bookingWidget())->set('website', 'spam')->call('book')->assertHasErrors('form');

    $rushed = bookingWidget()->call('pickDate', '2026-11-17')->call('pickSlot', '2026-11-17T10:00:00Z')->call('toDetails')->set('name', 'A')->set('email', 'a@example.com');
    $rushed->call('book')->assertHasErrors('form');

    foreach (range(1, 5) as $attempt) {
        filledBooking(bookingWidget())->set('email', 'not-an-email')->call('book');
    }

    filledBooking(bookingWidget())->call('book')->assertHasErrors('form')->assertSee('Too many attempts');

    expect(Booking::query()->count())->toBe(0);
});

it('says when there is nothing to book', function (): void {
    bookingWidget()->assertSee('There is nothing to book at the moment.');
});

it('shows times in the visitor\'s zone and keeps to the months that can be booked', function (): void {
    bookableService();

    $widget = bookingWidget()
        ->call('useTimezone', 'Asia/Kathmandu')
        ->assertSet('timezone', 'Asia/Kathmandu')
        ->call('pickDate', '2026-11-17');

    expect(array_column($widget->instance()->slotsForDate(), 'label'))->toContain('2:45 PM')
        ->and($widget->instance()->canGoBack())->toBeFalse();

    $widget->call('previousMonth')->assertSet('month', '2026-11')
        ->call('nextMonth')->call('nextMonth')->call('nextMonth')->assertSet('month', '2027-01')
        ->call('pickDate', '2026-12-01')->assertSet('date', '')
        ->call('useTimezone', 'Nowhere/Land')->assertSet('timezone', 'UTC')
        ->call('back')->assertSet('step', 'time');

    expect($widget->instance()->monthLabel())->toBe('January 2027')
        ->and($widget->instance()->weekdayNames())->toBe(['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su']);
});

it('ignores a service or time that is not on offer', function (): void {
    bookableService();
    bookableService();

    bookingWidget()
        ->call('chooseService', 999)->assertSet('step', 'service')
        ->set('serviceId', 999)->set('step', 'details')->call('back')->assertSet('step', 'time')->assertSeeHtml('wire:click="chooseService(');

    $widget = bookingWidget();
    $widget->set('serviceId', Booking::query()->count() + 999)->set('step', 'details')->set('name', 'Ada')->set('email', 'ada@example.com');
    test()->travel(5)->seconds();
    $widget->call('book')->assertSet('step', 'time')->assertHasErrors('time');
});
