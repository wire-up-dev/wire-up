<?php

declare(strict_types=1);

namespace App\Services;

use App\Notifications\BackupFailed;
use App\Notifications\ErrorReported;
use App\Notifications\OrderReceived;
use App\Notifications\SubmissionReceived;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;

final readonly class SlackWebhookChannel
{
    public function __construct(private PublicUrlGuard $urlGuard) {}

    public function send(object $notifiable, SubmissionReceived|OrderReceived|BackupFailed|ErrorReported $notification): void
    {
        $url = $notifiable instanceof AnonymousNotifiable ? $notifiable->routeNotificationFor(self::class) : null;

        if (! is_string($url) || $url === '') {
            return;
        }

        Http::withOptions($this->urlGuard->pinnedOptions($url))->timeout(10)->post($url, $notification->toSlackWebhook())->throw();
    }
}
