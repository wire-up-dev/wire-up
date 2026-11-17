<?php

declare(strict_types=1);

use App\Actions\CreateSubmissionAction;
use App\Enums\BlockType;
use App\Models\Block;
use App\Models\Locale;
use App\Models\Page;
use App\Models\Settings;
use App\Models\Submission;
use App\Notifications\SubmissionAutoReply;
use App\Notifications\SubmissionReceived;
use Illuminate\Mail\Mailer;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

/**
 * @param  array<string, mixed>  $overrides
 */
function contactBlock(array $overrides = []): Block
{
    $page = Page::factory()->create();

    return Block::factory()->create([
        'blockable_id' => $page->id,
        'blockable_type' => 'page',
        'type' => BlockType::CONTACT_FORM,
        'content' => array_replace_recursive(BlockType::CONTACT_FORM->defaultContent(), $overrides),
    ]);
}

function contactForm(Block $block): Testable
{
    $config = $block->content ?? [];
    unset($config['recipient']);

    return Livewire::test('site.contact-form', [
        'config' => $config,
        'blockId' => $block->id,
        'pageId' => $block->blockable_id,
    ]);
}

it('validates the enabled required fields', function (): void {
    $component = contactForm(contactBlock());

    test()->travel(5)->seconds();

    $component->call('submit')
        ->assertHasErrors(['name', 'email', 'message'])
        ->assertSet('sent', false);

    expect(Submission::query()->count())->toBe(0);
});

it('rejects an invalid email address', function (): void {
    $component = contactForm(contactBlock());

    test()->travel(5)->seconds();

    $component->set('name', 'Ada')
        ->set('email', 'not-an-email')
        ->set('message', 'Hello')
        ->call('submit')
        ->assertHasErrors(['email']);
});

it('persists and emails a successful submission', function (): void {
    Notification::fake();
    $block = contactBlock(['recipient' => 'owner@example.com', 'formName' => 'Massage enquiry']);

    $component = contactForm($block)
        ->set('name', 'Ada Lovelace')
        ->set('email', 'ada@example.com')
        ->set('message', 'Hello there');

    test()->travel(5)->seconds();

    $component->call('submit')
        ->assertHasNoErrors()
        ->assertSet('sent', true)
        ->assertSet('name', '');

    $this->assertDatabaseHas('submissions', [
        'type' => 'contact',
        'form_name' => 'Massage enquiry',
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'message' => 'Hello there',
        'page_id' => $block->blockable_id,
        'block_id' => $block->id,
        'locale' => 'en',
    ]);

    Notification::assertSentOnDemand(
        SubmissionReceived::class,
        fn (SubmissionReceived $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === ['owner@example.com'],
    );
});

it('posts the submission to the slack webhook when configured', function (): void {
    config()->set('services.slack.webhook_url', 'https://hooks.slack.com/services/T0/B0/xyz');
    Http::fake(['hooks.slack.com/*' => Http::response('ok')]);

    $component = contactForm(contactBlock(['formName' => 'Massage enquiry']))
        ->set('name', 'Ada Lovelace')
        ->set('email', 'ada@example.com')
        ->set('message', 'Hello there');

    test()->travel(5)->seconds();

    $component->call('submit')
        ->assertHasNoErrors()
        ->assertSet('sent', true);

    Http::assertSent(fn ($request): bool => (string) $request->url() === 'https://hooks.slack.com/services/T0/B0/xyz'
        && $request['text'] === 'New submission from Massage enquiry'
        && str_contains(json_encode($request['blocks']) ?: '', 'Ada Lovelace'));
});

it('routes to multiple block recipients', function (): void {
    Notification::fake();
    $block = contactBlock(['recipient' => 'one@example.com, two@example.com']);

    $component = contactForm($block)
        ->set('name', 'Ada')
        ->set('email', 'ada@example.com')
        ->set('message', 'Hi');

    test()->travel(5)->seconds();

    $component->call('submit')->assertHasNoErrors();

    Notification::assertSentOnDemand(
        SubmissionReceived::class,
        fn (SubmissionReceived $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === ['one@example.com', 'two@example.com'],
    );
});

it('falls back to the site contact email when the block has no recipient', function (): void {
    Notification::fake();
    Settings::set(['contact_email' => 'site@example.com']);
    $block = contactBlock(['recipient' => '']);

    $component = contactForm($block)
        ->set('name', 'Ada')
        ->set('email', 'ada@example.com')
        ->set('message', 'Hi');

    test()->travel(5)->seconds();

    $component->call('submit')->assertHasNoErrors();

    Notification::assertSentOnDemand(
        SubmissionReceived::class,
        fn (SubmissionReceived $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === ['site@example.com'],
    );
});

it('falls back to the mail from address when nothing else is set', function (): void {
    Notification::fake();
    config()->set('site.contact_email');
    config()->set('mail.from.address', 'from@example.com');
    $block = contactBlock(['recipient' => '']);

    $component = contactForm($block)
        ->set('name', 'Ada')
        ->set('email', 'ada@example.com')
        ->set('message', 'Hi');

    test()->travel(5)->seconds();

    $component->call('submit')->assertHasNoErrors();

    Notification::assertSentOnDemand(
        SubmissionReceived::class,
        fn (SubmissionReceived $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === ['from@example.com'],
    );
});

it('stores the submission but sends nothing when no recipient can be resolved', function (): void {
    Notification::fake();
    config()->set('site.contact_email');
    config()->set('mail.from.address', '');
    $block = contactBlock(['recipient' => '']);

    $component = contactForm($block)
        ->set('name', 'Ada')
        ->set('email', 'ada@example.com')
        ->set('message', 'Hi');

    test()->travel(5)->seconds();

    $component->call('submit')->assertHasNoErrors();

    expect(Submission::query()->count())->toBe(1);
    Notification::assertNothingSent();
});

it('drops submissions that fill the honeypot without showing success', function (): void {
    Notification::fake();
    $component = contactForm(contactBlock(['recipient' => 'owner@example.com']))
        ->set('name', 'Bot')
        ->set('email', 'bot@example.com')
        ->set('message', 'spam')
        ->set('website', 'http://spam.test');

    test()->travel(5)->seconds();

    $component->call('submit')
        ->assertHasErrors('form')
        ->assertSet('sent', false);

    expect(Submission::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('drops submissions made too quickly without showing success', function (): void {
    Notification::fake();
    $component = contactForm(contactBlock(['recipient' => 'owner@example.com']))
        ->set('name', 'Bot')
        ->set('email', 'bot@example.com')
        ->set('message', 'spam');

    $component->call('submit')
        ->assertHasErrors('form')
        ->assertSet('sent', false);

    expect(Submission::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('throttles repeated submissions from the same visitor', function (): void {
    $component = contactForm(contactBlock());

    test()->travel(5)->seconds();

    foreach (range(1, 5) as $ignored) {
        $component->call('submit');
    }

    $component->call('submit')->assertHasErrors('form');
});

it('validates custom fields of each type', function (): void {
    $block = contactBlock([
        'fieldOrder' => ['colour', 'age', 'terms'],
        'customFields' => [
            ['id' => 'colour', 'label' => ['en' => 'Colour'], 'type' => 'select', 'required' => true, 'options' => "Red\nBlue"],
            ['id' => 'age', 'label' => ['en' => 'Age'], 'type' => 'number', 'required' => false, 'options' => ''],
            ['id' => 'terms', 'label' => ['en' => 'Accept terms'], 'type' => 'checkbox', 'required' => true, 'options' => ''],
        ],
    ]);

    $component = contactForm($block)
        ->set('custom.colour', 'Green')
        ->set('custom.age', 'not-a-number')
        ->set('custom.terms', false);

    test()->travel(5)->seconds();

    $component->call('submit')
        ->assertHasErrors(['custom.colour', 'custom.age', 'custom.terms']);
});

it('persists custom field values into metadata', function (): void {
    Notification::fake();
    $block = contactBlock([
        'recipient' => 'owner@example.com',
        'customFields' => [
            ['id' => 'colour', 'label' => ['en' => 'Colour'], 'type' => 'select', 'required' => true, 'options' => "Red\nBlue"],
            ['id' => 'terms', 'label' => ['en' => 'Accept terms'], 'type' => 'checkbox', 'required' => true, 'options' => ''],
        ],
    ]);

    $component = contactForm($block)
        ->set('name', 'Ada')
        ->set('email', 'ada@example.com')
        ->set('message', 'Hi')
        ->set('custom.colour', 'Blue')
        ->set('custom.terms', true);

    test()->travel(5)->seconds();

    $component->call('submit')->assertHasNoErrors();

    $submission = Submission::query()->latest('id')->firstOrFail();

    expect($submission->orderedMetadata())->toBe([
        'colour' => ['label' => 'Colour', 'value' => 'Blue', 'position' => 0],
        'terms' => ['label' => 'Accept terms', 'value' => true, 'position' => 1],
    ]);
});

it('sends the visitor an automatic reply in their language without what they wrote', function (): void {
    Locale::query()->where('code', 'nl')->update(['active' => true]);
    cache()->forget('site-locales');
    Settings::set(['contact_email' => 'hello@example.com']);
    $block = contactBlock([
        'recipient' => 'owner@example.com',
        'autoReply' => true,
        'autoReplySubject' => ['en' => 'Thanks!', 'nl' => 'Bedankt!'],
        'autoReplyMessage' => ['en' => '<p>We will call you.</p>', 'nl' => '<p>We bellen je terug.</p>'],
    ]);
    app()->setLocale('nl');

    $component = contactForm($block)
        ->set('name', 'Ada Lovelace')
        ->set('email', 'ada@example.com')
        ->set('message', 'My secret question');

    test()->travel(5)->seconds();

    $component->call('submit')->assertHasNoErrors()->assertSet('sent', true);

    $reply = collect(resolve(Mailer::class)->getSymfonyTransport()->messages())
        ->map(fn (SentMessage $sent): Email => $sent->getOriginalMessage())
        ->first(fn (Email $email): bool => $email->getTo()[0]->getAddress() === 'ada@example.com');

    expect($reply)->not->toBeNull()
        ->and($reply->getSubject())->toBe('Bedankt!')
        ->and($reply->getReplyTo()[0]->getAddress())->toBe('hello@example.com')
        ->and($reply->getHtmlBody())->toContain('We bellen je terug.')
        ->and($reply->getHtmlBody())->not->toContain('My secret question', 'Ada Lovelace');
});

it('skips the automatic reply when it is off, there is no address or email is not set up', function (): void {
    Notification::fake();
    $submit = function (Block $block, string $email = 'ada@example.com'): void {
        $component = contactForm($block)->set('name', 'Ada')->set('email', $email)->set('message', 'Hello');
        test()->travel(5)->seconds();
        $component->call('submit')->assertHasNoErrors();
    };

    $submit(contactBlock(['recipient' => 'owner@example.com']));
    $submit(contactBlock(['recipient' => 'owner@example.com', 'autoReply' => true, 'fields' => ['email' => ['required' => false]]]), '');

    config(['mail.default' => 'log']);
    $submit(contactBlock(['recipient' => 'owner@example.com', 'autoReply' => true]));

    Notification::assertSentTimes(SubmissionAutoReply::class, 0);
    expect(Submission::query()->count())->toBe(3);
});

it('sends one address at most three automatic replies an hour', function (): void {
    Notification::fake();
    $block = contactBlock(['autoReply' => true]);

    foreach (['ada@example.com', 'Ada@Example.com ', 'ada@example.com', 'ADA@example.com', 'grace@example.com'] as $email) {
        resolve(CreateSubmissionAction::class)->handle(['type' => 'contact', 'email' => $email, 'block_id' => $block->id, 'locale' => 'en']);
    }

    Notification::assertSentTimes(SubmissionAutoReply::class, 4);
    Notification::assertSentOnDemand(SubmissionAutoReply::class, fn (SubmissionAutoReply $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'grace@example.com');
});

it('thanks the visitor in a card the width of the form it replaces', function (): void {
    $stacked = contactForm(contactBlock(['recipient' => 'owner@example.com', 'layout' => 'stacked']))
        ->set('name', 'Ada')->set('email', 'ada@example.com')->set('message', 'Hello');
    test()->travel(5)->seconds();
    $stacked->call('submit')->assertSet('sent', true)
        ->assertSeeHtml('wire-card bg-(--wire-card-bg) text-(--wire-card-text) mx-auto max-w-xl');

    $panelled = contactForm(contactBlock(['recipient' => 'owner@example.com', 'layout' => 'split', 'panelBg' => '#123456']))
        ->set('name', 'Ada')->set('email', 'ada@example.com')->set('message', 'Hello');
    test()->travel(5)->seconds();
    $panelled->call('submit')->assertSet('sent', true)
        ->assertSeeHtml('style="background-color:#123456')
        ->assertDontSeeHtml('mx-auto max-w-xl');
});
