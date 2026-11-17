<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Submission;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SubmissionAutoReply extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Submission $submission)
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
        $block = $this->submission->block;
        $subject = $block?->plainTextField('autoReplySubject') ?? '';

        $mail = new MailMessage()
            ->from(config()->string('mail.from.address'), $settings->brandName())
            ->subject($subject !== '' ? $subject : __('Thanks for your message'))
            ->markdown('mail.submission-auto-reply', [
                'body' => $block?->text('autoReplyMessage') ?? '',
                'brand' => $settings->brandName(),
            ]);

        if ($settings->contactEmail() !== '') {
            $mail->replyTo($settings->contactEmail());
        }

        return $mail;
    }
}
