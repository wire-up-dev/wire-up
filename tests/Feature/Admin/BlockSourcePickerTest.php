<?php

declare(strict_types=1);

use App\Models\Page;
use App\Models\Record;
use App\Models\RecordType;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

function pageWithBlocks(string $title, array $blocks): Page
{
    $page = Page::factory()->create(['title' => $title]);
    $page->updateBlocks($blocks);

    return $page;
}

it('lists pages and records that have blocks, grouped by where they live', function (): void {
    $this->actingAsAdmin();
    $current = pageWithBlocks('Current page', [['id' => 'new-a', 'type' => 'divider', 'content' => []]]);
    pageWithBlocks('About us', [['id' => 'new-b', 'type' => 'divider', 'content' => []], ['id' => 'new-c', 'type' => 'spacer', 'content' => []]]);
    Page::factory()->create(['title' => 'Empty page']);
    $services = RecordType::factory()->create(['name' => 'Services']);
    Record::factory()->for($services, 'recordType')->create(['title' => 'Coaching'])
        ->updateBlocks([['id' => 'new-d', 'type' => 'divider', 'content' => []]]);
    $internal = RecordType::factory()->create(['name' => 'Internal notes', 'admin_only' => true]);
    Record::factory()->for($internal, 'recordType')->create(['title' => 'Hidden note']);

    Livewire::test('admin.block-source-picker', ['excludeType' => 'page', 'excludeId' => $current->id])
        ->assertSee('Pages')
        ->assertSee('About us')
        ->assertSee('2 blocks')
        ->assertSee('Services')
        ->assertSee('Coaching')
        ->assertDontSee('Current page')
        ->assertDontSee('Empty page')
        ->assertDontSee('Hidden note')
        ->set('search', 'coach')
        ->assertSee('Coaching')
        ->assertDontSee('About us')
        ->set('search', 'nothing like this')
        ->assertSee('No pages or records match your search.');
});

it('leaves out the record being edited and types the editor cannot see', function (): void {
    $services = RecordType::factory()->create(['key' => 'services', 'name' => 'Services']);
    $current = Record::factory()->for($services, 'recordType')->create(['title' => 'This record']);
    $current->updateBlocks([['id' => 'new-a', 'type' => 'divider', 'content' => []]]);
    Record::factory()->for($services, 'recordType')->create(['title' => 'Other record'])
        ->updateBlocks([['id' => 'new-b', 'type' => 'divider', 'content' => []]]);
    pageWithBlocks('A page', [['id' => 'new-c', 'type' => 'divider', 'content' => []]]);

    $role = Role::query()->create(['key' => 'service-editor', 'name' => 'Service editor', 'abilities' => ['records.services.view'], 'bypass' => false, 'is_protected' => false]);
    $this->actingAs(User::factory()->role($role)->create(['active' => true]));

    Livewire::test('admin.block-source-picker', ['excludeType' => 'record', 'excludeId' => $current->id])
        ->assertSee('Other record')
        ->assertDontSee('This record')
        ->assertDontSee('A page');
});

it('shows the blocks of the chosen page and sends the one to copy', function (): void {
    $this->actingAsAdmin();
    $page = pageWithBlocks('About us', [
        ['id' => 'new-a', 'type' => 'hero', 'content' => ['heading' => ['en' => '<p>Welcome aboard</p>']]],
        ['id' => 'new-b', 'type' => 'rich-text', 'content' => ['body' => ['en' => '<p>Some longer text about us.</p>']]],
    ]);
    $hero = $page->blocks()->where('type', 'hero')->sole();

    Livewire::test('admin.block-source-picker')
        ->call('open', 'page', $page->id)
        ->assertSee('About us')
        ->assertSee('Welcome aboard')
        ->assertSee('Some longer text about us.')
        ->call('copy', $hero->id)
        ->assertDispatched('block-source-selected', blockId: $hero->id)
        ->call('back')
        ->assertSet('sourceId', null)
        ->call('open', 'page', $page->id)
        ->dispatch('block-picker-opened')
        ->assertSet('sourceId', null);
});

it('says when a chosen record has no blocks left', function (): void {
    $this->actingAsAdmin();
    $record = Record::factory()->for(RecordType::factory()->create(), 'recordType')->create(['title' => 'Bare record']);

    Livewire::test('admin.block-source-picker')
        ->call('open', 'record', $record->id)
        ->assertSee('Bare record')
        ->assertSee('This page has no blocks.');
});

it('falls back to the list for a source that is unknown or off limits', function (): void {
    $page = pageWithBlocks('Private page', [['id' => 'new-a', 'type' => 'divider', 'content' => []]]);
    $role = Role::query()->create(['key' => 'no-pages', 'name' => 'No pages', 'abilities' => ['inbox.view'], 'bypass' => false, 'is_protected' => false]);
    $this->actingAs(User::factory()->role($role)->create(['active' => true]));

    Livewire::test('admin.block-source-picker')
        ->call('open', 'page', $page->id)
        ->assertDontSee('Private page')
        ->call('open', 'something-else', $page->id)
        ->assertSee('No pages or records match your search.');
});
