<?php

declare(strict_types=1);

use App\Actions\ImportMediaFromUrlAction;
use App\Actions\ImportPexelsMediaAction;
use App\Mail\IntegrationTestMail;
use App\Services\NewsletterService;
use App\Services\SettingsNavigation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

arch()->preset()->php();
arch()->preset()->laravel()->ignoring(IntegrationTestMail::class);
arch()->preset()->security()->ignoring([ImportPexelsMediaAction::class, ImportMediaFromUrlAction::class, NewsletterService::class]);

arch('every application file declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('application classes are final')
    ->expect(['App\Actions', 'App\Services', 'App\Models', 'App\Jobs', 'App\Mcp\Tools', 'App\Http', 'App\Console\Commands', 'App\Notifications', 'App\Listeners'])
    ->classes()
    ->toBeFinal();

arch('actions are single-purpose handle classes')
    ->expect('App\Actions')
    ->toHaveMethod('handle')
    ->toHaveSuffix('Action');

foreach (['App\\Actions', 'App\\Models', 'App\\Mcp', 'App\\Jobs', 'App\\Services'] as $layer) {
    arch("{$layer} stays free of admin UI dependencies")
        ->expect($layer)
        ->not->toUse('Flux')
        ->not->toUse('Livewire')
        ->ignoring(SettingsNavigation::class);
}

arch('controllers are only reached through routes')
    ->expect('App\Http\Controllers')
    ->not->toBeUsed();

arch('MCP tools carry a name and description for clients')
    ->expect('App\Mcp\Tools')
    ->toExtend(Tool::class)
    ->toHaveSuffix('Tool')
    ->toHaveAttribute(Name::class)
    ->toHaveAttribute(Description::class);

arch('queued jobs cap their attempts and running time')
    ->expect('App\Jobs')
    ->toImplement(ShouldQueue::class)
    ->toHaveAttribute(Tries::class)
    ->toHaveAttribute(Timeout::class);

arch('enums are backed enums')
    ->expect('App\Enums')
    ->toBeStringBackedEnums();

it('keeps every migration forward-only, with no down method', function (string $path): void {
    expect(method_exists(require $path, 'down'))->toBeFalse();
})->with(fn (): array => collect(glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [])
    ->mapWithKeys(fn (string $path): array => [basename($path) => $path])
    ->all());

it('installs laravel/mcp on live sites, not only through a development package', function (): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require'])->toHaveKey('laravel/mcp');
});
