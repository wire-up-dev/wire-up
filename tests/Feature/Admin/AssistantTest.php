<?php

declare(strict_types=1);

use App\Ai\Agents\SiteAssistant;
use App\Enums\ContentStatus;
use App\Enums\RevisionSource;
use App\Mcp\Support\RemoteAccess;
use App\Models\Activity;
use App\Models\Media;
use App\Models\Page;
use App\Models\Record;
use App\Models\Redirect;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Responses\Data\ToolCall;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

mutates(RemoteAccess::class);

beforeEach(function (): void {
    ob_start(fn (string $buffer): string => '');
});

afterEach(function (): void {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
});

/**
 * @param  array<int, string>  $toolNames
 */
function seedAssistantMessage(string $conversationId, int $userId, string $role, string $content, array $toolNames = []): void
{
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => (new User)->getMorphClass(),
        'participant_id' => $userId,
        'agent' => SiteAssistant::class,
        'role' => $role,
        'content' => $content,
        'attachments' => '[]',
        'steps' => json_encode([[
            'content' => $content,
            'tool_calls' => array_map(fn (string $name): array => ['id' => (string) Str::uuid(), 'name' => $name, 'arguments' => [], 'result' => 'ok'], $toolNames),
            'reasoning' => '',
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ]]),
        'status' => 'completed',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('rate limits rapid messages', function (): void {
    $this->actingAsAdmin();

    SiteAssistant::fake(['Hi.']);

    foreach (range(1, 20) as $ignored) {
        RateLimiter::hit('assistant:'.auth()->id(), 60);
    }

    Livewire::test('admin.assistant')
        ->call('send', 'Hello there')
        ->assertSet('messages.0.error', true)
        ->assertSee('too quickly');

    SiteAssistant::assertNeverPrompted();
});

it('shows the empty state before any messages', function (): void {
    $this->actingAsAdmin();

    Livewire::test('admin.assistant')
        ->assertSet('messages', [])
        ->assertSet('conversationId', null)
        ->assertSee('Ask me to build');
});

it('sends a prompt and appends the exchange', function (): void {
    $this->actingAsAdmin();
    config()->set('site.ai_model', 'claude-opus-4-8');

    SiteAssistant::fake(['Here is your About page.']);

    Livewire::test('admin.assistant')
        ->call('send', 'Build an About page')
        ->assertSet('messages.0.role', 'user')
        ->assertSet('messages.0.content', 'Build an About page')
        ->assertSet('messages.1.role', 'assistant')
        ->assertSet('messages.1.content', 'Here is your About page.')
        ->assertSet('messages.1.animate', true)
        ->assertSee('Here is your About page.');

    SiteAssistant::assertPrompted('Build an About page');
});

it('prompts without a model override when none is configured', function (): void {
    $this->actingAsAdmin();
    config()->set('site.ai_model', '');

    SiteAssistant::fake(['Done.']);

    Livewire::test('admin.assistant')
        ->call('send', 'Change the theme')
        ->assertSet('messages.1.content', 'Done.');

    SiteAssistant::assertPrompted('Change the theme');
});

it('records tool activity as the assistant works', function (): void {
    $this->actingAsAdmin();
    config()->set('site.ai_model', '');

    SiteAssistant::fake([
        new ToolCall('call-1', 'get-settings', []),
        'I checked your settings — all good.',
    ]);

    Livewire::test('admin.assistant')
        ->call('send', 'What theme am I using?')
        ->assertSet('messages.1.role', 'assistant')
        ->assertSet('messages.1.content', 'I checked your settings — all good.')
        ->assertSet('messages.1.tools.0.name', 'get-settings')
        ->assertSet('messages.1.tools.0.status', 'done');
});

it('renders tool chips for every activity state', function (): void {
    $this->actingAsAdmin();

    $html = Livewire::test('admin.assistant')->instance()->toolChipsHtml([
        ['name' => 'create-page', 'status' => 'running'],
        ['name' => 'publish-page', 'status' => 'done'],
        ['name' => 'update-design', 'status' => 'failed'],
        ['name' => 'mystery-tool', 'status' => 'running'],
    ]);

    expect($html)
        ->toContain('Creating page')
        ->toContain('animate-pulse')
        ->toContain('Published')
        ->toContain('&check;')
        ->toContain('Updating design')
        ->toContain('&times;')
        ->toContain('mystery-tool');
});

it('asks for confirmation before publishing and does not publish yet', function (): void {
    $this->actingAsAdmin();
    $page = Page::factory()->create(['title' => 'Launch Me', 'status' => ContentStatus::DRAFT]);

    SiteAssistant::fake([
        new ToolCall('c1', 'publish-page', ['page' => $page->id]),
        'The page is ready — confirm below to publish it.',
    ]);

    Livewire::test('admin.assistant')
        ->call('send', 'Publish the Launch Me page')
        ->assertSet('messages.1.pending.0.name', 'publish-page')
        ->assertSet('messages.1.pending.0.status', 'awaiting')
        ->assertSee('Publish “Launch Me”?');

    expect($page->refresh()->status)->toBe(ContentStatus::DRAFT);
});

it('publishes only after the owner confirms', function (): void {
    $this->actingAsAdmin();
    $page = Page::factory()->create(['status' => ContentStatus::DRAFT]);

    SiteAssistant::fake([new ToolCall('c1', 'publish-page', ['page' => $page->id]), 'Ready to publish.']);

    Livewire::test('admin.assistant')
        ->call('send', 'Publish it')
        ->call('confirmAction', 1, 0)
        ->assertSet('messages.1.pending.0.status', 'confirmed');

    expect($page->refresh()->status)->toBe(ContentStatus::PUBLISHED);
});

it('leaves the page unpublished when the owner cancels', function (): void {
    $this->actingAsAdmin();
    $page = Page::factory()->create(['status' => ContentStatus::DRAFT]);

    SiteAssistant::fake([new ToolCall('c1', 'publish-page', ['page' => $page->id]), 'Ready to publish.']);

    Livewire::test('admin.assistant')
        ->call('send', 'Publish it')
        ->call('rejectAction', 1, 0)
        ->assertSet('messages.1.pending.0.status', 'rejected');

    expect($page->refresh()->status)->toBe(ContentStatus::DRAFT);
});

it('shows failures inline in the chat', function (): void {
    $this->actingAsAdmin();

    SiteAssistant::fake(fn (): never => throw new RuntimeException('provider down'));

    Livewire::test('admin.assistant')
        ->call('send', 'Build a page')
        ->assertSet('messages.0.role', 'user')
        ->assertSet('messages.1.role', 'assistant')
        ->assertSet('messages.1.error', true)
        ->assertSee('I ran into a problem');
});

it('ignores an empty prompt without erroring', function (): void {
    $this->actingAsAdmin();

    SiteAssistant::fake();

    Livewire::test('admin.assistant')
        ->call('send', '   ')
        ->assertHasNoErrors()
        ->assertSet('messages', []);

    SiteAssistant::assertNeverPrompted();
});

it('loads prior conversation history on mount', function (): void {
    $user = User::factory()->create(['role' => 'owner']);
    $this->actingAs($user);

    $conversationId = resolve(ConversationStore::class)->storeConversation((new User)->getMorphClass(), $user->id, 'Earlier chat');
    seedAssistantMessage($conversationId, $user->id, 'user', 'Make a hero section');
    seedAssistantMessage($conversationId, $user->id, 'assistant', 'Added a **hero** to the home page.');

    Livewire::test('admin.assistant')
        ->assertSet('conversationId', $conversationId)
        ->assertSet('messages.0.content', 'Make a hero section')
        ->assertSet('messages.1.content', 'Added a **hero** to the home page.')
        ->assertSeeHtml('<strong>hero</strong>');
});

it('rebuilds tool activity chips when reloading a past chat', function (): void {
    $user = User::factory()->create(['role' => 'owner']);
    $this->actingAs($user);

    $conversationId = resolve(ConversationStore::class)->storeConversation((new User)->getMorphClass(), $user->id, 'Earlier chat');
    seedAssistantMessage($conversationId, $user->id, 'user', 'Build an About page');
    seedAssistantMessage($conversationId, $user->id, 'assistant', 'Done — added the page.', ['create-page', 'update-page-blocks', 'publish-page']);

    Livewire::test('admin.assistant')
        ->assertSet('messages.1.tools.0.name', 'create-page')
        ->assertSet('messages.1.tools.1.name', 'update-page-blocks')
        ->assertSee('Created page')
        ->assertSee('Updated page')
        ->assertDontSee('Published');
});

it('starts a new conversation', function (): void {
    $user = User::factory()->create(['role' => 'owner']);
    $this->actingAs($user);

    $conversationId = resolve(ConversationStore::class)->storeConversation((new User)->getMorphClass(), $user->id, 'Earlier chat');
    seedAssistantMessage($conversationId, $user->id, 'assistant', 'Previous reply.');

    Livewire::test('admin.assistant')
        ->assertSet('conversationId', $conversationId)
        ->call('startNewConversation')
        ->assertSet('conversationId', null)
        ->assertSet('messages', []);
});

it('mounts the assistant in the admin shell only when enabled and configured', function (): void {
    $this->actingAsAdmin();

    config()->set('site.ai_api_key', '');
    $this->get(route('admin.dashboard'))->assertDontSeeLivewire('admin.assistant');

    config()->set('site.ai_api_key', 'sk-configured');
    $this->get(route('admin.dashboard'))->assertSeeLivewire('admin.assistant');
});

it('forbids sending for users without the assistant ability', function (): void {
    $member = User::factory()->create(['role' => 'member']);
    $this->actingAs($member);

    SiteAssistant::fake();

    Livewire::test('admin.assistant')
        ->call('send', 'Build me a page')
        ->assertForbidden();

    SiteAssistant::assertNeverPrompted();
});

it('deletes only after the owner confirms, without the model passing the confirm flag', function (): void {
    $this->actingAsAdmin();
    $page = Page::factory()->create();

    SiteAssistant::fake([new ToolCall('c1', 'delete-page', ['page' => $page->id]), 'Ready to delete.']);

    $component = Livewire::test('admin.assistant')
        ->call('send', 'Delete it')
        ->assertSet('messages.1.pending.0.status', 'awaiting');

    expect(Page::query()->whereKey($page->id)->exists())->toBeTrue();

    $component->call('confirmAction', 1, 0)
        ->assertSet('messages.1.pending.0.status', 'confirmed');

    expect(Page::query()->whereKey($page->id)->exists())->toBeFalse();
});

it('refuses to confirm an action the user’s role does not allow', function (): void {
    $role = Role::factory()->create(['abilities' => ['assistant.use', 'pages.view'], 'bypass' => false]);
    $this->actingAs(User::factory()->role($role)->create());
    $page = Page::factory()->create();

    SiteAssistant::fake([new ToolCall('c1', 'delete-page', ['page' => $page->id]), 'Ready to delete.']);

    Livewire::test('admin.assistant')
        ->call('send', 'Delete it')
        ->call('confirmAction', 1, 0)
        ->assertSet('messages.1.pending.0.status', 'rejected')
        ->assertSet('messages.2.error', true)
        ->assertSet('messages.2.content', 'Your role can’t delete Pages.');

    expect(Page::query()->whereKey($page->id)->exists())->toBeTrue();
});

it('does not let the browser plant a pending action', function (): void {
    $this->actingAsAdmin();

    Livewire::test('admin.assistant')
        ->set('messages', [['role' => 'assistant', 'content' => '', 'pending' => [['name' => 'delete-page', 'args' => ['page' => 1], 'status' => 'awaiting']]]]);
})->throws(CannotUpdateLockedPropertyException::class);

it('says exactly what each approval will do', function (string $tool, Closure $arguments, string $label, bool $destructive): void {
    $this->actingAsAdmin();

    SiteAssistant::fake([new ToolCall('c1', $tool, $arguments()), 'Ready.']);

    $component = Livewire::test('admin.assistant')->call('send', 'Go ahead')->assertSee($label);

    expect($component->instance()->isDestructive($component->get('messages.1.pending.0')))->toBe($destructive);
})->with([
    'delete page' => ['delete-page', fn (): array => ['page' => Page::factory()->create(['title' => 'Old news'])->id], 'Delete the page “Old news”? This cannot be undone.', true],
    'delete missing page' => ['delete-page', fn (): array => ['page' => 999999], 'Delete this page? This cannot be undone.', true],
    'delete record' => ['delete-record', fn (): array => ['record' => Record::factory()->create(['title' => 'Old shoe'])->id], 'Delete “Old shoe”? This cannot be undone.', true],
    'delete media' => ['delete-media', fn (): array => ['media' => Media::factory()->create(['filename' => 'cat.jpg'])->id], 'Delete the file “cat.jpg”? This cannot be undone.', true],
    'delete redirect' => ['delete-redirect', fn (): array => ['redirect' => Redirect::factory()->create(['from' => '/old', 'to' => '/new'])->id], 'Delete the redirect from /old?', true],
    'replace everywhere' => ['replace-text', fn (): array => ['find' => 'Acme', 'replace' => 'Apex'], 'Replace “Acme” with “Apex” everywhere it appears?', true],
    'replace in some pages' => ['replace-text', fn (): array => ['find' => 'Acme', 'replace' => 'Apex', 'pages' => [1]], 'Replace “Acme” with “Apex” in the chosen pages and records?', true],
    'remove everywhere' => ['replace-text', fn (): array => ['find' => 'Acme', 'replace' => ''], 'Remove “Acme” everywhere it appears?', true],
    'remove in some records' => ['replace-text', fn (): array => ['find' => 'Acme', 'replace' => '', 'records' => [1]], 'Remove “Acme” from the chosen pages and records?', true],
    'scaffold site' => ['scaffold-site', fn (): array => ['pages' => [['title' => 'Home'], ['title' => 'About']]], 'Set up 2 pages and replace the header and footer menus?', true],
    'publish record' => ['publish-record', fn (): array => ['record' => Record::factory()->create(['title' => 'New shoe'])->id], 'Publish “New shoe”?', false],
    'unpublish record' => ['publish-record', fn (): array => ['record' => Record::factory()->create(['title' => 'New shoe'])->id, 'status' => 'draft'], 'Set “New shoe” back to draft?', false],
    'publish missing record' => ['publish-record', fn (): array => ['record' => 999999], 'Publish this record?', false],
    'unpublish missing record' => ['publish-record', fn (): array => ['record' => 999999, 'status' => 'draft'], 'Set this record back to draft?', false],
]);

it('says so when an approved action does not go through', function (): void {
    $this->actingAsAdmin();
    $page = Page::factory()->create();

    SiteAssistant::fake([new ToolCall('c1', 'delete-page', ['page' => $page->id]), 'Ready to delete.']);

    $component = Livewire::test('admin.assistant')->call('send', 'Delete it');

    $page->delete();

    $component->call('confirmAction', 1, 0)
        ->assertSet('messages.1.pending.0.status', 'failed')
        ->assertSet('messages.2.error', true)
        ->assertSee('Did not go through');
});

it('marks an approved change as made by the assistant', function (): void {
    $this->actingAsAdmin();
    $page = Page::factory()->create(['status' => ContentStatus::DRAFT]);

    SiteAssistant::fake([new ToolCall('c1', 'publish-page', ['page' => $page->id]), 'Ready to publish.']);

    Livewire::test('admin.assistant')->call('send', 'Publish it')->call('confirmAction', 1, 0);

    expect(Activity::query()->sole()->source)->toBe(RevisionSource::ASSISTANT);
});
