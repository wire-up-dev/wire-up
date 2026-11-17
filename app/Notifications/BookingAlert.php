<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Booking;
use App\Services\BookingService;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class BookingAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public const string NEW = 'new';

    public const string RESCHEDULED = 'rescheduled';

    public const string CANCELLED = 'cancelled';

    public const string CLASHED = 'clashed';

    public function __construct(public Booking $booking, public string $kind)
    {
        $this->afterCommit = true;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable ? array_keys($notifiable->routes) : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $bookings = resolve(BookingService::class);
        $booking = $this->booking;

        $mail = new MailMessage()
            ->from(config()->string('mail.from.address'), SettingsService::current()->brandName())
            ->subject($this->subject())
            ->markdown('mail.booking-alert', [
                'kind' => $this->kind,
                'booking' => $booking,
                'when' => $bookings->formatTime($booking->starts_at, $bookings->timezone(), config()->string('app.default_locale', 'en')),
                'viewUrl' => route('admin.bookings-show', $booking),
                'refundUrl' => $this->refundUrl(),
            ]);

        if (is_string($booking->email) && $booking->email !== '') {
            $mail->replyTo($booking->email);
        }

        return $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSlackWebhook(): array
    {
        $bookings = resolve(BookingService::class);
        $when = $bookings->formatTime($this->booking->starts_at, $bookings->timezone(), config()->string('app.default_locale', 'en'));

        return [
            'text' => $this->subject(),
            'blocks' => [
                ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => $this->subject(), 'emoji' => true]],
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*'.$this->slackEscape($this->booking->label()).'* · '.$this->slackEscape($this->booking->service_name)."\n".$this->slackEscape($when)]],
                ['type' => 'actions', 'elements' => [[
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => __('View booking'), 'emoji' => true],
                    'url' => route('admin.bookings-show', $this->booking),
                ]]],
            ],
        ];
    }

    private function refundUrl(): ?string
    {
        $order = $this->booking->order;

        return $this->kind === self::CANCELLED && $order !== null && $order->status->canBeRefunded()
            ? route('admin.orders-show', ['order' => $order, 'refund' => 1])
            : null;
    }

    private function subject(): string
    {
        $who = $this->booking->name ?: __('A visitor');

        return match ($this->kind) {
            self::RESCHEDULED => __(':who moved their booking', ['who' => $who]),
            self::CANCELLED => __(':who cancelled their booking', ['who' => $who]),
            self::CLASHED => __('A late payment clashes with another booking'),
            default => __('New booking from :who', ['who' => $who]),
        };
    }

    private function slackEscape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
