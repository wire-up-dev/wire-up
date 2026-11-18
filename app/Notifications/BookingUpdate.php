<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\BookingCalendarFile;
use App\Services\BookingService;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;

#[DeleteWhenMissingModels]
final class BookingUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    public const string CONFIRMED = 'confirmed';

    public const string RESCHEDULED = 'rescheduled';

    public const string CANCELLED = 'cancelled';

    public const string REMINDER = 'reminder';

    public function __construct(public Booking $booking, public string $kind)
    {
        $this->afterCommit = true;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $settings = SettingsService::current();
        $bookings = resolve(BookingService::class);
        $booking = $this->booking;
        $location = $bookings->serviceLocation($booking);

        $mail = new MailMessage()
            ->from(config()->string('mail.from.address'), $settings->brandName())
            ->subject($this->subject())
            ->markdown('mail.booking-update', [
                'kind' => $this->kind,
                'booking' => $booking,
                'when' => $bookings->formatTime($booking->starts_at, $booking->timezone, $booking->locale),
                'location' => $location,
                'manageUrl' => $booking->status === BookingStatus::CONFIRMED ? $bookings->manageUrl($booking) : null,
                'brand' => $settings->brandName(),
            ]);

        if ($this->kind !== self::REMINDER) {
            $mail->attachData(resolve(BookingCalendarFile::class)->forCustomer($booking, $location), 'booking.ics', ['mime' => 'text/calendar; charset=utf-8']);
        }

        if ($settings->contactEmail() !== '') {
            $mail->replyTo($settings->contactEmail());
        }

        return $mail;
    }

    private function subject(): string
    {
        $service = $this->booking->service_name;

        return match ($this->kind) {
            self::RESCHEDULED => __('Your booking has moved: :service', ['service' => $service]),
            self::CANCELLED => __('Your booking is cancelled: :service', ['service' => $service]),
            self::REMINDER => __('Reminder: :service is coming up', ['service' => $service]),
            default => __('Your booking is confirmed: :service', ['service' => $service]),
        };
    }
}
