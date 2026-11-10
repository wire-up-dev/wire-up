<?php

declare(strict_types=1);

use App\Services\UpdateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

it('shows a note instead of an error box when the admin stops answering during an update', function (): void {
    $this->actingAsAdmin();
    resolve(UpdateService::class)->markRunning('v1.0.0', 'Install PHP dependencies');

    $page = visit(route('admin.settings-updates'));

    $page->assertSee('Install PHP dependencies')
        ->assertScript("document.querySelector('[data-update-interrupted]').classList.contains('hidden')", true);

    app()->bind(UpdateService::class, fn (): never => throw new RuntimeException('The update is switching versions'));

    $page->wait(5)
        ->assertScript("document.querySelector('[data-update-interrupted]').classList.contains('hidden')", false)
        ->assertScript("document.getElementById('livewire-error') === null", true)
        ->assertSee('The admin is switching to the new version');
});

/**
 * @param  list<string>  $notes
 */
function offerReleaseWithNotes(array $notes): string
{
    $root = storage_path('framework/testing/updates-'.Str::random(8));
    config()->set('wireup.version_file', $root.'/version');
    resolve(UpdateService::class)->writeCurrentVersion('v0.8.0');
    Cache::forever('wireup:update:latest', ['version' => 'v0.8.1', 'checked_at' => now()->toIso8601String(), 'notes' => [['version' => 'v0.8.1', 'notes' => $notes]], 'failed' => false]);

    return $root;
}

it('shows the first lines of long release notes so Update now stays in view', function (): void {
    $root = offerReleaseWithNotes(array_map(fn (int $number): string => "Change {$number}, described in enough words to wrap across the column of the updates screen.", range(1, 12)));
    $this->actingAsAdmin();

    $page = visit(route('admin.settings-updates'));

    $page->assertSee('Show all changes')
        ->assertScript("document.querySelector('[data-release-notes]').clientHeight <= 160", true)
        ->assertScript("[...document.querySelectorAll('button')].find((button) => button.textContent.includes('Update now')).getBoundingClientRect().bottom < window.innerHeight", true);

    $page->click('Show all changes')
        ->assertSee('Show less')
        ->assertScript("(() => { const notes = document.querySelector('[data-release-notes]'); return notes.scrollHeight <= notes.clientHeight + 1; })()", true)
        ->assertNoJavaScriptErrors();

    File::deleteDirectory($root);
});

it('shows short release notes in full, without a link', function (): void {
    $root = offerReleaseWithNotes(['One small fix.']);
    $this->actingAsAdmin();

    visit(route('admin.settings-updates'))
        ->assertSee('One small fix.')
        ->assertScript("[...document.querySelectorAll('button')].some((button) => button.textContent.includes('Show all changes') && button.offsetParent !== null)", false);

    File::deleteDirectory($root);
});
