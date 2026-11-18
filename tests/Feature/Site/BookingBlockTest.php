<?php

declare(strict_types=1);

use App\Enums\BlockType;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Record;
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

    return $widget->call('pickSlot', $slot)
        ->set('name', 'Ada Lovelace')
        ->set('email', 'Ada@Example.com')
        ->set('notes', 'First session')
        ->call('toConfirm');
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
        ->set('name', 'Ada')
        ->set('email', 'ada@example.com')
        ->call('toConfirm')
        ->assertHasErrors('time')
        ->assertSet('step', 'time')
        ->call('pickDate', '2026-11-18')
        ->assertSet('date', '2026-11-18');

    filledBooking($widget)
        ->assertSet('date', '2026-11-17')
        ->assertSet('step', 'confirm')
        ->assertSee('Ada Lovelace')
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

    $widget->call('pickSlot', '2026-11-17T09:00:00Z')->call('toConfirm')->call('book')->assertSet('step', 'done');

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
    bookingWidget(['phone' => 'weird'])->call('pickSlot', '2026-11-17T11:00:00Z')->assertSee('Phone (optional)');
    filledBooking(bookingWidget(['phone' => 'weird']), '2026-11-17T11:00:00Z')->set('phone', '555 0100')->call('book')->assertHasNoErrors();

    expect(Booking::query()->orderBy('id')->pluck('phone')->all())->toBe([null, '555 0100']);
});

it('refuses bots, rushed submissions, staff acting as a member and repeat attempts', function (): void {
    bookableService();

    filledBooking(bookingWidget())->set('website', 'spam')->call('book')->assertHasErrors('form');

    $rushed = bookingWidget()->call('pickSlot', '2026-11-17T10:00:00Z')->set('name', 'A')->set('email', 'a@example.com');
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
        ->and($widget->instance()->availableDays()[0])->toBe(['date' => '2026-11-16', 'label' => 'Mon, November 16'])
        ->and($widget->instance()->canGoBack())->toBeFalse();

    $widget->call('previousMonth')->assertSet('month', '2026-11')
        ->call('nextMonth')->call('nextMonth')->call('nextMonth')->assertSet('month', '2027-01')
        ->call('pickDate', '2026-12-01')->assertSet('date', '')
        ->call('pickSlot', '2026-12-01T10:00:00Z')->assertSet('slot', '')
        ->call('useTimezone', 'Nowhere/Land')->assertSet('timezone', 'UTC');

    expect($widget->instance()->monthLabel())->toBe('January 2027')
        ->and($widget->instance()->activeDate())->toBe('2027-01-01');
});

it('opens on the first month that still has free times', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-30 18:00', 'UTC'));
    bookableService();

    bookingWidget()->assertSet('month', '2026-12')->assertSeeHtml('data-available-day="2026-12-01"');
});

it('shows one step at a time and lets the visitor go back to change a choice', function (): void {
    bookableService(['heading' => ['en' => 'Intro call']]);
    bookableService(['heading' => ['en' => 'Deep dive']]);

    $widget = bookingWidget()
        ->assertSeeHtml('data-booking-step="service"')
        ->assertDontSeeHtml('data-booking-step="time"')
        ->call('goTo', 'details')
        ->assertSet('step', 'service');

    $first = Record::query()->orderBy('id')->firstOrFail();

    $widget->call('chooseService', $first->id)
        ->assertSeeHtml('data-booking-step="time"')
        ->assertDontSeeHtml('data-booking-step="service"')
        ->call('pickSlot', '2026-11-17T10:00:00Z')
        ->assertSet('step', 'details')
        ->assertSee('Intro call · Tuesday 17 November 2026, 10:00 AM')
        ->call('goTo', 'time')
        ->assertSet('step', 'time')
        ->call('goTo', 'service')
        ->assertSet('step', 'service')
        ->call('goTo', 'nowhere')
        ->assertSet('step', 'service');
});

it('keeps a single service on its time step', function (): void {
    bookableService();

    bookingWidget()->assertSet('step', 'time')->call('goTo', 'service')->assertSet('step', 'time');
});

it('ignores a service or time that is not on offer', function (): void {
    bookableService();
    bookableService();

    bookingWidget()->call('chooseService', 999)->assertSet('step', 'service');

    $widget = bookingWidget();
    $widget->set('serviceId', 999)->set('step', 'confirm')->set('name', 'Ada')->set('email', 'ada@example.com');
    test()->travel(5)->seconds();
    $widget->call('book')->assertSet('step', 'time')->assertHasErrors('time');
});

it('lines its heading up on the left unless it is set to the center, and keeps the steps centered', function (): void {
    bookableService();

    bookingWidget(['heading' => ['en' => '<p>Book</p>']])
        ->assertSeeHtml('class="space-y-4"')
        ->assertDontSeeHtml('space-y-4 text-center')
        ->assertSeeHtml('class="mx-auto max-w-2xl space-y-12"');

    bookingWidget(['heading' => ['en' => '<p>Book</p>'], 'align' => 'center'])
        ->assertSeeHtml('class="space-y-4 text-center"')
        ->assertSeeHtml('class="mx-auto max-w-2xl space-y-12"');
});

it('tells the visitor when Stripe cannot start the payment and frees the time', function (): void {
    connectStripe();
    stripe()->fail('POST', '/v1/checkout/sessions', 'Stripe is down');
    bookableService(['current_price' => '80', 'charge_when_booking' => true]);

    filledBooking(bookingWidget())
        ->call('book')
        ->assertNoRedirect()
        ->assertSet('step', 'time')
        ->assertSee('Payment is not available right now. Please try again later.');

    expect(Booking::query()->sole()->status)->toBe(BookingStatus::EXPIRED);
});

function respondWithCheckoutPerBooking(): void
{
    stripe()->respond('POST', '/v1/checkout/sessions', fn (array $params): array => [
        'id' => 'cs_'.$params['metadata']['booking_reference'],
        'object' => 'checkout.session',
        'url' => 'https://checkout.stripe.com/c/pay/'.$params['metadata']['booking_reference'],
    ]);
}

it('lets go of the earlier unpaid time when the visitor books again', function (): void {
    connectStripe();
    respondWithCheckoutPerBooking();
    stripe()->respond('POST', '/v1/checkout/sessions/*/expire', ['object' => 'checkout.session', 'status' => 'expired']);
    bookableService(['current_price' => '80', 'charge_when_booking' => true]);

    filledBooking(bookingWidget())->call('book');
    filledBooking(bookingWidget(), '2026-11-17T12:00:00Z')->call('book');

    [$first, $second] = Booking::query()->oldest('id')->get()->all();

    expect($first->status)->toBe(BookingStatus::EXPIRED)
        ->and($first->claims()->count())->toBe(0)
        ->and($second->status)->toBe(BookingStatus::PENDING_PAYMENT);

    stripe()->assertSent('POST', '/v1/checkout/sessions/cs_'.$first->reference.'/expire');
});

it('keeps the new booking going when Stripe cannot let go of the earlier one', function (): void {
    connectStripe();
    respondWithCheckoutPerBooking();
    stripe()->fail('POST', '/v1/checkout/sessions/*/expire', 'Stripe is down');
    stripe()->fail('GET', '/v1/checkout/sessions/*', 'Stripe is down');
    bookableService(['current_price' => '80', 'charge_when_booking' => true]);

    filledBooking(bookingWidget())->call('book');
    filledBooking(bookingWidget(), '2026-11-17T12:00:00Z')->call('book')->assertRedirectContains('https://checkout.stripe.com/c/pay/');

    expect(Booking::query()->where('status', BookingStatus::PENDING_PAYMENT)->count())->toBe(2);
});

it('limits how many free bookings one email address can make in an hour', function (): void {
    bookableService();

    foreach (['2026-11-17T09:00:00Z', '2026-11-17T10:00:00Z', '2026-11-17T11:00:00Z'] as $slot) {
        filledBooking(bookingWidget(), $slot)->call('book')->assertSet('step', 'done');
    }

    filledBooking(bookingWidget(), '2026-11-17T12:00:00Z')
        ->call('book')
        ->assertSee('This email address has made several bookings in the last hour. Please try again later.');

    expect(Booking::query()->count())->toBe(3);
});

it('offers no times until the availability has been saved', function (): void {
    Settings::set(['booking_timezone' => null]);
    bookableService();

    bookingWidget()->assertDontSeeHtml('data-available-day')->assertSee('There are no free times this month.');
});

it('keeps an older time zone name the browser reports', function (): void {
    $olderName = collect(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC))->diff(DateTimeZone::listIdentifiers())->first();

    if (! is_string($olderName)) {
        $this->markTestSkipped('This PHP build lists no older time zone names.');
    }

    bookableService();

    bookingWidget()
        ->call('useTimezone', $olderName)
        ->assertSet('timezone', $olderName)
        ->assertSeeHtml('value="'.$olderName.'"');
});
