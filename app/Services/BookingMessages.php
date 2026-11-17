<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\WebhookEvent;
use App\Models\Booking;
use App\Notifications\BookingAlert;
use App\Notifications\BookingUpdate;
use Illuminate\Support\Facades\Notification;

final readonly class BookingMessages
{
    public function customer(Booking $booking, string $kind): void
    {
        if (! is_string($booking->email) || $booking->email === '' || ! SettingsService::current()->mailConfigured()) {
            return;
        }

        Notification::route('mail', $booking->email)->notify(new BookingUpdate($booking, $kind)->locale($booking->locale));
    }

    public function owner(Booking $booking, string $kind): void
    {
        $settings = SettingsService::current();
        $ownerEmail = $settings->contactEmail() ?: config()->string('mail.from.address', '');

        if ($ownerEmail !== '' && $settings->mailConfigured()) {
            Notification::route('mail', $ownerEmail)->notify(new BookingAlert($booking, $kind));
        }

        $webhookUrl = config('services.slack.webhook_url');

        if (is_string($webhookUrl) && $webhookUrl !== '') {
            Notification::route(SlackWebhookChannel::class, $webhookUrl)->notify(new BookingAlert($booking, $kind));
        }
    }

    public function webhook(Booking $booking, WebhookEvent $event): void
    {
        $webhooks = resolve(Webhooks::class);
        $webhooks->dispatch($event, $webhooks->bookingData($booking));
    }
}
