<?php

declare(strict_types=1);

use App\Enums\BlockType;
use App\Models\Block;
use App\Models\Page;
use App\Models\Submission;
use App\Notifications\SubmissionAutoReply;
use Illuminate\Notifications\AnonymousNotifiable;

it('sends over the mail channel only', function (): void {
    expect(new SubmissionAutoReply(Submission::factory()->make())->via(new AnonymousNotifiable))->toBe(['mail']);
});

it('falls back to a short reply when the form has no wording or is gone', function (): void {
    $page = Page::factory()->create();
    $block = Block::factory()->create(['blockable_id' => $page->id, 'blockable_type' => 'page', 'type' => BlockType::CONTACT_FORM, 'content' => ['autoReply' => true]]);

    foreach ([Submission::factory()->create(['block_id' => $block->id]), Submission::factory()->create(['block_id' => null])] as $submission) {
        $mail = new SubmissionAutoReply($submission)->toMail(new AnonymousNotifiable);

        expect($mail->subject)->toBe('Thanks for your message')
            ->and($mail->replyTo)->toBeEmpty()
            ->and((string) $mail->render())->toContain('We got your message and will reply soon.');
    }
});
