<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\WebhookEvent;
use App\Models\Block;
use App\Models\Submission;
use App\Notifications\SubmissionAutoReply;
use App\Notifications\SubmissionReceived;
use App\Services\SettingsService;
use App\Services\SlackWebhookChannel;
use App\Services\Webhooks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

final readonly class CreateSubmissionAction
{
    private const int AUTO_REPLIES_PER_HOUR = 3;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $recipients
     */
    public function handle(array $attributes, array $recipients = []): Submission
    {
        return DB::transaction(function () use ($attributes, $recipients): Submission {
            if (isset($attributes['block_id']) && ! Block::query()->whereKey($attributes['block_id'])->exists()) {
                $attributes['block_id'] = null;
            }

            $submission = Submission::query()->create($attributes);

            $webhooks = resolve(Webhooks::class);
            $webhooks->dispatch(
                $submission->type === 'newsletter' ? WebhookEvent::NEWSLETTER_SUBSCRIBED : WebhookEvent::SUBMISSION_CREATED,
                $webhooks->submissionData($submission),
            );

            if ($recipients !== []) {
                Notification::route('mail', $recipients)->notify(new SubmissionReceived($submission));
            }

            $webhookUrl = config('services.slack.webhook_url');

            if ($submission->type !== 'newsletter' && is_string($webhookUrl) && $webhookUrl !== '') {
                Notification::route(SlackWebhookChannel::class, $webhookUrl)->notify(new SubmissionReceived($submission));
            }

            $this->sendAutoReply($submission);

            return $submission;
        });
    }

    private function sendAutoReply(Submission $submission): void
    {
        $email = is_string($submission->email) ? mb_strtolower(mb_trim($submission->email)) : '';

        if ($submission->type !== 'contact' || $email === '' || ! SettingsService::current()->mailConfigured()) {
            return;
        }

        $block = $submission->block;

        if (! $block instanceof Block || ! filter_var(data_get($block->content, 'autoReply'), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $throttleKey = 'contact-auto-reply:'.hash('xxh128', $email);

        if (RateLimiter::tooManyAttempts($throttleKey, self::AUTO_REPLIES_PER_HOUR)) {
            return;
        }

        RateLimiter::hit($throttleKey, 3600);

        Notification::route('mail', $email)->notify(new SubmissionAutoReply($submission)->locale($submission->locale ?? config()->string('app.default_locale', 'en')));
    }
}
