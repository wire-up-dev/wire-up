<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Settings;
use App\Models\User;
use App\Notifications\BookingAlert;
use App\Notifications\BookingUpdate;
use App\Services\SlackWebhookChannel;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;

it('writes each visitor email with the right subject, details and calendar file', function (string $kind, string $subject, string $heading, bool $attaches): void {
    Settings::set(['contact_email' => 'hello@example.com']);
    $service = bookableService(['booking_location' => ['en' => '12 Main Street']]);
    $booking = Booking::factory()->create([
        'record_id' => $service->id,
        'service_name' => 'Intro call',
        'starts_at' => CarbonImmutable::parse('2026-11-20 15:00', 'UTC'),
        'timezone' => 'America/New_York',
    ]);

    $notification = new BookingUpdate($booking, $kind);
    $mail = $notification->toMail(new AnonymousNotifiable);
    $html = (string) $mail->render();

    expect($notification->via(new AnonymousNotifiable))->toBe(['mail'])
        ->and($mail->subject)->toBe($subject)
        ->and($mail->replyTo)->toBe([['hello@example.com', null]])
        ->and($html)->toContain($heading, 'Friday 20 November 2026, 10:00 AM', '12 Main Street', $booking->reference, '/booking/manage/'.$booking->manage_token)
        ->and($mail->rawAttachments)->toHaveCount($attaches ? 1 : 0);
})->with([
    'confirmed' => [BookingUpdate::CONFIRMED, 'Your booking is confirmed: Intro call', 'Your booking is confirmed', true],
    'moved' => [BookingUpdate::RESCHEDULED, 'Your booking has moved: Intro call', 'Your booking has moved', true],
    'reminder' => [BookingUpdate::REMINDER, 'Reminder: Intro call is coming up', 'See you soon', false],
]);

it('tells the visitor about a cancellation without a link to change it', function (): void {
    $booking = Booking::factory()->cancelled()->create();

    $mail = new BookingUpdate($booking, BookingUpdate::CANCELLED)->toMail(new AnonymousNotifiable);

    expect($mail->subject)->toBe('Your booking is cancelled: Coaching session')
        ->and($mail->replyTo)->toBeEmpty()
        ->and((string) $mail->render())->toContain('This booking won’t go ahead')->not->toContain('/booking/manage/');
});

it('alerts the owner by email and Slack, with a refund button for a paid cancellation', function (): void {
    $order = Order::factory()->paid()->create();
    $booking = Booking::factory()->cancelled()->create(['order_id' => $order->id, 'phone' => '555 0100', 'notes' => 'Allergic to cats']);

    $notification = new BookingAlert($booking, BookingAlert::CANCELLED);
    $mail = $notification->toMail(new AnonymousNotifiable);
    $slack = $notification->toSlackWebhook();

    expect($notification->via((new AnonymousNotifiable)->route(SlackWebhookChannel::class, 'https://hooks.slack.com/services/T/B/x')))->toBe([SlackWebhookChannel::class])
        ->and($notification->via(new User))->toBe(['mail'])
        ->and($mail->subject)->toBe('Ada Lovelace cancelled their booking')
        ->and($mail->replyTo)->toBe([['ada@example.com', null]])
        ->and((string) $mail->render())->toContain('Refund the payment', 'refund=1', '555 0100', 'Allergic to cats')
        ->and($slack['text'])->toBe('Ada Lovelace cancelled their booking')
        ->and($slack['blocks'][2]['elements'][0]['url'])->toBe(route('admin.bookings-show', $booking));
});

it('words each owner alert for what happened', function (string $kind, string $subject): void {
    $booking = Booking::factory()->create(['name' => null, 'email' => null, 'status' => BookingStatus::CONFIRMED]);
    $mail = new BookingAlert($booking, $kind)->toMail(new AnonymousNotifiable);

    expect($mail->subject)->toBe($subject)
        ->and($mail->replyTo)->toBeEmpty()
        ->and((string) $mail->render())->toContain('No name given')->not->toContain('Refund the payment');
})->with([
    'new' => [BookingAlert::NEW, 'New booking from A visitor'],
    'moved' => [BookingAlert::RESCHEDULED, 'A visitor moved their booking'],
    'clash' => [BookingAlert::CLASHED, 'A late payment clashes with another booking'],
    'unpaid cancel' => [BookingAlert::CANCELLED, 'A visitor cancelled their booking'],
]);

it('leaves the refund button out once the order was refunded', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::REFUNDED]);
    $booking = Booking::factory()->cancelled()->create(['order_id' => $order->id]);

    expect((string) new BookingAlert($booking, BookingAlert::CANCELLED)->toMail(new AnonymousNotifiable)->render())->not->toContain('Refund the payment');
});
