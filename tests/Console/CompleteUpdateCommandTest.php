<?php

declare(strict_types=1);

use App\Console\Commands\CompleteUpdateCommand;
use App\Enums\ActivityEvent;
use App\Enums\RevisionSource;
use App\Models\Activity;
use App\Services\UpdateService;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $root = storage_path('framework/testing/wireup-'.Str::random(8));

    config()->set('wireup.version_file', $root.'/version');
    config()->set('wireup.build_path', $root.'/build');
    config()->set('app.maintenance.driver', 'cache');
    config()->set('app.maintenance.store', 'array');

    Process::fake(['*ls-remote*' => Process::result(output: "aaa\trefs/tags/v1.0.0")]);
});

afterEach(function (): void {
    File::deleteDirectory(dirname((string) config('wireup.version_file')));
});

it('brings the site back up, records the update and forgets the rollback copy', function (): void {
    $updates = resolve(UpdateService::class);
    $updates->writeCurrentVersion('v1.0.0');
    $updates->markRunning('v1.0.0', 'Disable maintenance mode');
    File::ensureDirectoryExists(config('wireup.build_path').'-rollback');
    app()->maintenanceMode()->activate([]);

    $this->artisan(CompleteUpdateCommand::class, ['tag' => 'v1.0.0'])
        ->expectsOutputToContain('Wire Up is now on v1.0.0.')
        ->assertExitCode(0);

    expect(app()->maintenanceMode()->active())->toBeFalse()
        ->and($updates->state()['status'])->toBe('finished')
        ->and($updates->latestVersion())->toBe('v1.0.0')
        ->and(File::exists(config('wireup.build_path').'-rollback'))->toBeFalse()
        ->and(Activity::query()->sole()->only(['event', 'source', 'subject_label']))->toBe(['event' => ActivityEvent::SYSTEM_UPDATED, 'source' => RevisionSource::SYSTEM, 'subject_label' => 'v1.0.0']);
});

it('records why the site could not come back up and leaves it in maintenance mode', function (): void {
    app()->instance(MaintenanceMode::class, new class implements MaintenanceMode
    {
        public function activate(array $payload): void {}

        public function deactivate(): void
        {
            throw new RuntimeException('the cache store is unreachable');
        }

        public function active(): bool
        {
            return true;
        }

        public function data(): array
        {
            return [];
        }
    });

    $this->artisan(CompleteUpdateCommand::class, ['tag' => 'v1.0.0'])
        ->expectsOutputToContain('the cache store is unreachable')
        ->assertExitCode(1);

    $state = resolve(UpdateService::class)->state();

    expect($state['status'])->toBe('failed')
        ->and($state['step'])->toBe('Disable maintenance mode')
        ->and($state['output'])->toContain('the cache store is unreachable')
        ->and($state['site_down'])->toBeTrue()
        ->and(Activity::query()->count())->toBe(0);
});
