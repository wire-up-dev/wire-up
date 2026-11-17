<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ReleaseOrderAction;
use App\Actions\SendBookingReminderAction;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Models\Booking;
use App\Models\Order;
use App\Services\BookingService;
use App\Services\StripeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Email visitors a reminder before their booking and free up unpaid holds')]
#[Signature('wireup:send-booking-reminders')]
final class SendBookingRemindersCommand extends Command
{
    public function handle(BookingService $bookings, SendBookingReminderAction $remind, ReleaseOrderAction $release, StripeService $stripe): int
    {
        $hours = $bookings->reminderHours();

        if ($hours > 0) {
            Booking::query()
                ->where('status', BookingStatus::CONFIRMED)
                ->whereNull('reminded_at')
                ->whereNotNull('email')
                ->whereBetween('starts_at', [now(), now()->addHours($hours)])
                ->get()
                ->each(fn (Booking $booking): bool => $remind->handle($booking));
        }

        if (! $stripe->configured()) {
            Order::query()
                ->where('status', OrderStatus::PENDING)
                ->where('expires_at', '<', now()->subMinutes(5))
                ->whereHas('booking')
                ->get()
                ->each(fn (Order $order) => $release->handle($order));
        }

        return self::SUCCESS;
    }
}
