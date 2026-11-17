<?php

declare(strict_types=1);

use App\Enums\ActivityEvent;
use App\Enums\WebhookEvent;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Notifications\BookingAlert;
use App\Notifications\BookingUpdate;
use App\Services\ActivityLog;
use App\Services\BookingMessages;
use App\Services\SlackWebhookChannel;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

it('posts new bookings to Slack and skips email while email is not set up', function (): void {
    Notification::fake();
    config(['mail.default' => 'log', 'services.slack.webhook_url' => 'https://hooks.slack.com/services/T/B/x']);
    $booking = Booking::factory()->create();
    $messages = resolve(BookingMessages::class);

    $messages->customer($booking, BookingUpdate::CONFIRMED);
    $messages->owner($booking, BookingAlert::NEW);

    Notification::assertSentOnDemandTimes(BookingUpdate::class, 0);
    Notification::assertSentOnDemandTimes(BookingAlert::class, 1);
    Notification::assertSentOnDemand(BookingAlert::class, fn (BookingAlert $notification, array $channels, AnonymousNotifiable $notifiable): bool => isset($notifiable->routes[SlackWebhookChannel::class]));
});

it('sends booking events to webhooks that listen for them', function (): void {
    Queue::fake();
    Webhook::factory()->listeningTo([WebhookEvent::BOOKING_CONFIRMED])->create();
    $booking = Booking::factory()->create(['name' => 'Ada Lovelace']);

    resolve(BookingMessages::class)->webhook($booking, WebhookEvent::BOOKING_CONFIRMED);

    $payload = WebhookDelivery::query()->sole()->payload;

    expect(data_get($payload, 'event'))->toBe('booking.confirmed')
        ->and(data_get($payload, 'data.reference'))->toBe($booking->reference)
        ->and(data_get($payload, 'data.customer.name'))->toBe('Ada Lovelace')
        ->and(data_get($payload, 'data.admin_url'))->toBe(route('admin.bookings-show', $booking))
        ->and(WebhookEvent::BOOKING_RESCHEDULED->label())->toBe('Booking moved');
});

it('links booking activity back to the booking', function (): void {
    $booking = Booking::factory()->create();

    ActivityLog::record(ActivityEvent::BOOKING_CANCELLED, $booking);

    $activity = Activity::query()->sole();

    expect($activity->subject_label)->toBe($booking->reference)
        ->and($activity->subjectUrl())->toBe(route('admin.bookings-show', $booking));
});
