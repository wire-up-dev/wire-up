<?php

declare(strict_types=1);

use App\Console\Commands\UpdateCommand;
use App\Services\BackupService;
use App\Services\DatabaseDumper;
use App\Services\ReleaseSignature;
use App\Services\SiteBundle;
use App\Services\UpdateService;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Support\ReleaseArchive;

beforeEach(function (): void {
    $root = storage_path('framework/testing/wireup-'.Str::random(8));

    config()->set('wireup.version_file', $root.'/version');
    config()->set('wireup.updates_path', $root.'/updates');
    config()->set('wireup.build_path', $root.'/build');
    config()->set('wireup.backup_path', $root.'/backups');

    foreach (['APP_CONFIG_CACHE' => 'config.php', 'APP_ROUTES_CACHE' => 'routes.php', 'APP_EVENTS_CACHE' => 'events.php'] as $variable => $file) {
        $_SERVER[$variable] = $root.'/cache/'.$file;
    }

    Process::fake([
        '*rev-parse*' => Process::result(output: "abc1234def\n"),
        '*cat-file*' => fn (PendingProcess $process): FakeProcessResult => Process::result(output: "object abc1234def\ntype commit\ntag ".end($process->command)."\n"),
    ]);
});

function preUpdateBackup(string $tag): string
{
    return 'db-backup-'.now()->format('Y-m-d-His')."-before-{$tag}.".resolve(DatabaseDumper::class)->extension();
}

afterEach(function (): void {
    File::deleteDirectory(dirname((string) config('wireup.version_file')));

    unset($_SERVER['APP_CONFIG_CACHE'], $_SERVER['APP_ROUTES_CACHE'], $_SERVER['APP_EVENTS_CACHE']);
});

it('updates to the latest release', function (): void {
    Process::fake([
        '*ls-remote*' => Process::result(output: "aaa\trefs/tags/v1.0.0"),
        '*show*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    resolve(UpdateService::class)->writeCurrentVersion('v0.9.0');

    $this->artisan(UpdateCommand::class)
        ->expectsOutputToContain('Wire Up is now on v1.0.0.')
        ->assertExitCode(0);

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'down']);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['git', 'fetch', '--tags', '--force', 'origin']);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['git', '-c', 'advice.detachedHead=false', 'checkout', '--force', 'v1.0.0']);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist']);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'wireup:backup', '--name='.preUpdateBackup('v1.0.0')]);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'migrate', '--force']);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['npm', '--version']);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['npm', 'ci']);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['npm', 'run', 'build']);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'optimize']);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'queue:restart']);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'wireup:update:complete', 'v1.0.0']);
    Process::assertDidntRun(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'up']);

    $service = resolve(UpdateService::class);

    expect($service->currentVersion())->toBe('v1.0.0')
        ->and($service->latestVersion())->toBe('v1.0.0');
});

it('clears the cached configuration before the new files arrive, so the admin never mixes them', function (): void {
    $cached = [app()->getCachedConfigPath(), app()->getCachedRoutesPath(), app()->getCachedEventsPath()];
    $presentAtCheckout = null;

    foreach ($cached as $file) {
        File::ensureDirectoryExists(dirname($file));
        File::put($file, '<?php return [];');
    }

    Process::fake([
        '*checkout*' => function () use ($cached, &$presentAtCheckout): FakeProcessResult {
            $presentAtCheckout = array_values(array_filter($cached, File::exists(...)));

            return Process::result();
        },
        '*' => Process::result(),
    ]);
    resolve(UpdateService::class)->writeCurrentVersion('v0.9.0');

    $this->artisan(UpdateCommand::class, ['--tag' => 'v1.0.0'])->assertExitCode(0);

    expect($cached[0])->toStartWith(dirname((string) config('wireup.version_file')))
        ->and($presentAtCheckout)->toBe([]);

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'optimize']);
});

it('updates to a pinned tag', function (): void {
    Process::fake([
        '*ls-remote*' => Process::result(output: "aaa\trefs/tags/v1.0.0"),
        '*' => Process::result(),
    ]);

    resolve(UpdateService::class)->writeCurrentVersion('v0.9.0');

    $this->artisan(UpdateCommand::class, ['--tag' => 'v1.0.0'])
        ->expectsOutputToContain('Wire Up is now on v1.0.0.')
        ->assertExitCode(0);

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['git', '-c', 'advice.detachedHead=false', 'checkout', '--force', 'v1.0.0']);
});

it('reports being already up to date', function (): void {
    Process::fake(['*ls-remote*' => Process::result(output: "aaa\trefs/tags/v1.0.0")]);

    resolve(UpdateService::class)->writeCurrentVersion('v1.0.0');

    $this->artisan(UpdateCommand::class)
        ->expectsOutputToContain('Already up to date.')
        ->assertExitCode(0);

    Process::assertDidntRun(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'down']);

    expect(resolve(UpdateService::class)->state()['status'])->toBe('idle');
});

it('reruns the update on the same version when forced', function (): void {
    Process::fake([
        '*ls-remote*' => Process::result(output: "aaa\trefs/tags/v1.0.0"),
        '*' => Process::result(),
    ]);

    resolve(UpdateService::class)->writeCurrentVersion('v1.0.0');

    $this->artisan(UpdateCommand::class, ['--tag' => 'v1.0.0', '--force' => true])
        ->expectsOutputToContain('Wire Up is now on v1.0.0.')
        ->assertExitCode(0);

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'down']);
});

it('fails when no release tag exists', function (): void {
    Process::fake([
        '*ls-remote*' => Process::result(output: ''),
        '*describe*' => Process::result(exitCode: 1),
        '*fetch*' => Process::result(),
    ]);

    $this->artisan(UpdateCommand::class)
        ->expectsOutputToContain('No release tag to install.')
        ->assertExitCode(1);

    Process::assertDidntRun(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'down']);

    expect(resolve(UpdateService::class)->state()['status'])->toBe('idle');
});

it('records the failure when the new version cannot finish the update', function (): void {
    Process::fake([
        '*wireup:update:complete*' => Process::result(errorOutput: 'up boom', exitCode: 1),
        '*ls-remote*' => Process::result(output: "aaa\trefs/tags/v1.0.0"),
        '*' => Process::result(),
    ]);

    resolve(UpdateService::class)->writeCurrentVersion('v0.9.0');

    $this->artisan(UpdateCommand::class, ['--tag' => 'v1.0.0'])
        ->expectsOutputToContain('up boom')
        ->assertExitCode(1);

    $service = resolve(UpdateService::class);

    expect($service->state()['status'])->toBe('failed')
        ->and($service->state()['step'])->toBe('Disable maintenance mode')
        ->and($service->state()['site_down'])->toBeTrue()
        ->and($service->currentVersion())->toBe('v1.0.0');
});

it('keeps the failure the new version recorded while finishing the update', function (): void {
    Process::fake([
        '*wireup:update:complete*' => function (): FakeProcessResult {
            resolve(UpdateService::class)->markFailed('v1.0.0', 'Disable maintenance mode', 'Failed to disable maintenance mode: disk full.');

            return Process::result(exitCode: 1);
        },
        '*' => Process::result(),
    ]);

    resolve(UpdateService::class)->writeCurrentVersion('v0.9.0');

    $this->artisan(UpdateCommand::class, ['--tag' => 'v1.0.0'])
        ->expectsOutputToContain('php artisan up')
        ->assertExitCode(1);

    expect(resolve(UpdateService::class)->state()['output'])->toBe('Failed to disable maintenance mode: disk full.');
});

it('brings the site back up without touching the code when the database backup fails', function (): void {
    Process::fake([
        '*wireup:backup*' => Process::result(errorOutput: 'backup boom', exitCode: 1),
        '*' => Process::result(),
    ]);

    resolve(UpdateService::class)->writeCurrentVersion('v0.9.0');

    $this->artisan(UpdateCommand::class, ['--tag' => 'v1.0.0'])
        ->expectsOutputToContain('backup boom')
        ->expectsOutputToContain('Wire Up was put back on v0.9.0')
        ->assertExitCode(1);

    Process::assertDidntRun(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'migrate', '--force']);
    Process::assertDidntRun(fn (PendingProcess $process): bool => $process->command === ['git', '-c', 'advice.detachedHead=false', 'checkout', '--force', 'abc1234def']);
    Process::assertDidntRun(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'optimize']);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'up']);

    $state = resolve(UpdateService::class)->state();

    expect($state['status'])->toBe('failed')
        ->and($state['step'])->toBe('Back up database')
        ->and($state['site_down'])->toBeFalse()
        ->and($state['rolled_back_to'])->toBe('v0.9.0');
});

it('rolls back to the previous version when a migration fails', function (): void {
    Process::fake([
        '*migrate*' => Process::result(output: 'migration exploded', exitCode: 1),
        '*' => Process::result(),
    ]);

    resolve(UpdateService::class)->writeCurrentVersion('v0.9.0');

    $this->artisan(UpdateCommand::class, ['--tag' => 'v1.0.0'])
        ->expectsOutputToContain('migration exploded')
        ->expectsOutputToContain('Wire Up was put back on v0.9.0. The site is live again.')
        ->doesntExpectOutputToContain('php artisan up')
        ->assertExitCode(1);

    Process::assertRanInOrder([
        ['git', 'rev-parse', 'HEAD'],
        ['git', 'remote', 'get-url', 'origin'],
        ['git', 'fetch', '--tags', '--force', 'origin'],
        resolve(ReleaseSignature::class)->verifyTagCommand('v1.0.0'),
        ['git', 'cat-file', 'tag', 'v1.0.0'],
        ['npm', '--version'],
        [PHP_BINARY, 'artisan', 'down'],
        [PHP_BINARY, 'artisan', 'wireup:backup', '--name='.preUpdateBackup('v1.0.0')],
        ['git', '-c', 'advice.detachedHead=false', 'checkout', '--force', 'v1.0.0'],
        ['composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist'],
        [PHP_BINARY, 'artisan', 'migrate', '--force'],
        ['git', '-c', 'advice.detachedHead=false', 'checkout', '--force', 'abc1234def'],
        ['composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist'],
        [PHP_BINARY, 'artisan', 'wireup:backup:restore-database', preUpdateBackup('v1.0.0'), '--force'],
        [PHP_BINARY, 'artisan', 'optimize'],
        [PHP_BINARY, 'artisan', 'queue:restart'],
        [PHP_BINARY, 'artisan', 'up'],
    ]);

    $service = resolve(UpdateService::class);
    $state = $service->state();

    expect($service->currentVersion())->toBe('v0.9.0')
        ->and($state['status'])->toBe('failed')
        ->and($state['step'])->toBe('Run database migrations')
        ->and($state['output'])->toBe('migration exploded')
        ->and($state['site_down'])->toBeFalse()
        ->and($state['rolled_back_to'])->toBe('v0.9.0');
});

it('leaves the site in maintenance mode when rolling back fails', function (): void {
    Process::fake([
        '*migrate*' => Process::result(output: 'migration exploded', exitCode: 1),
        '*restore-database*' => Process::result(errorOutput: 'restore boom', exitCode: 1),
        '*' => Process::result(),
    ]);

    resolve(UpdateService::class)->writeCurrentVersion('v0.9.0');

    $this->artisan(UpdateCommand::class, ['--tag' => 'v1.0.0'])
        ->expectsOutputToContain('restore boom')
        ->expectsOutputToContain('php artisan up')
        ->assertExitCode(1);

    Process::assertDidntRun(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'up']);

    $service = resolve(UpdateService::class);
    $state = $service->state();

    expect($service->currentVersion())->toBe('v0.9.0')
        ->and($state['step'])->toBe('Run database migrations')
        ->and($state['output'])->toContain('migration exploded')
        ->and($state['output'])->toContain('Rolling back stopped at "Restore database"')
        ->and($state['output'])->toContain('restore boom')
        ->and($state['site_down'])->toBeTrue()
        ->and($state['rolled_back_to'])->toBeNull();
});

it('puts the previous frontend assets back when the update fails after replacing them', function (): void {
    $archive = ReleaseArchive::make(['build/manifest.json' => '{"release":"v1.0.0"}']);
    $build = (string) config('wireup.build_path');
    File::ensureDirectoryExists($build);
    File::put($build.'/manifest.json', '{"release":"v0.9.0"}');
    Process::fake([
        '*get-url*' => Process::result(output: ReleaseArchive::REMOTE),
        '*optimize*' => Process::sequence()
            ->push(Process::result(errorOutput: 'optimize boom', exitCode: 1))
            ->push(Process::result()),
        '*' => Process::result(),
    ]);
    Http::fake([
        ReleaseArchive::url('v1.0.0', 'build.tar.gz.sha256') => Http::response(hash('sha256', $archive)),
        ReleaseArchive::url('v1.0.0', 'build.tar.gz.sha256.sig') => Http::response(ReleaseArchive::SIGNATURE),
        ReleaseArchive::url('v1.0.0') => Http::response($archive),
    ]);

    resolve(UpdateService::class)->writeCurrentVersion('v0.9.0');

    $this->artisan(UpdateCommand::class, ['--tag' => 'v1.0.0'])
        ->expectsOutputToContain('optimize boom')
        ->expectsOutputToContain('Wire Up was put back on v0.9.0')
        ->assertExitCode(1);

    expect(File::get($build.'/manifest.json'))->toBe('{"release":"v0.9.0"}')
        ->and(File::exists($build.'-rollback'))->toBeFalse()
        ->and(resolve(UpdateService::class)->state()['step'])->toBe('Cache configuration');
});

it('does not start when the current commit cannot be read', function (): void {
    Process::fake([
        '*rev-parse*' => Process::result(errorOutput: 'not a git repository', exitCode: 128),
        '*' => Process::result(),
    ]);

    resolve(UpdateService::class)->writeCurrentVersion('v0.9.0');

    $this->artisan(UpdateCommand::class, ['--tag' => 'v1.0.0'])
        ->expectsOutputToContain('the update did not start')
        ->assertExitCode(1);

    Process::assertDidntRun(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'down']);

    $state = resolve(UpdateService::class)->state();

    expect($state['status'])->toBe('failed')
        ->and($state['site_down'])->toBeFalse();
});

it('installs the prebuilt frontend assets instead of building them on the server', function (): void {
    $archive = ReleaseArchive::make(['build/manifest.json' => '{"release":"v1.0.0"}']);
    Process::fake([
        '*get-url*' => Process::result(output: ReleaseArchive::REMOTE),
        '*ls-remote*' => Process::result(output: "aaa\trefs/tags/v1.0.0"),
        '*' => Process::result(),
    ]);
    Http::fake([
        ReleaseArchive::url('v1.0.0', 'build.tar.gz.sha256') => Http::response(hash('sha256', $archive)),
        ReleaseArchive::url('v1.0.0', 'build.tar.gz.sha256.sig') => Http::response(ReleaseArchive::SIGNATURE),
        ReleaseArchive::url('v1.0.0') => Http::response($archive),
    ]);

    resolve(UpdateService::class)->writeCurrentVersion('v0.9.0');

    $this->artisan(UpdateCommand::class, ['--tag' => 'v1.0.0'])
        ->expectsOutputToContain('Wire Up is now on v1.0.0.')
        ->assertExitCode(0);

    Process::assertDidntRun(fn (PendingProcess $process): bool => is_array($process->command) && $process->command[0] === 'npm');

    expect(File::get(config('wireup.build_path').'/manifest.json'))->toBe('{"release":"v1.0.0"}');
});

it('stops before maintenance mode when the prebuilt assets fail verification', function (): void {
    Process::fake([
        '*get-url*' => Process::result(output: ReleaseArchive::REMOTE),
        '*' => Process::result(),
    ]);
    Http::fake([
        ReleaseArchive::url('v1.0.0', 'build.tar.gz.sha256') => Http::response(str_repeat('a', 64)),
        ReleaseArchive::url('v1.0.0', 'build.tar.gz.sha256.sig') => Http::response(ReleaseArchive::SIGNATURE),
        ReleaseArchive::url('v1.0.0') => Http::response('tampered'),
    ]);

    resolve(UpdateService::class)->writeCurrentVersion('v0.9.0');

    $this->artisan(UpdateCommand::class, ['--tag' => 'v1.0.0'])
        ->expectsOutputToContain('did not match their checksum')
        ->doesntExpectOutputToContain('maintenance mode')
        ->assertExitCode(1);

    Process::assertDidntRun(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'down']);

    $state = resolve(UpdateService::class)->state();

    expect($state['status'])->toBe('failed')
        ->and($state['step'])->toBe('Download frontend assets')
        ->and($state['site_down'])->toBeFalse();
});

it('refuses a release whose tag is not signed by a trusted key', function (): void {
    Process::fake([
        '*verify-tag*' => Process::result(errorOutput: 'error: no signature found', exitCode: 1),
        '*' => Process::result(),
    ]);

    resolve(UpdateService::class)->writeCurrentVersion('v0.9.0');

    $this->artisan(UpdateCommand::class, ['--tag' => 'v1.0.0'])
        ->expectsOutputToContain('no signature found')
        ->assertExitCode(1);

    Process::assertDidntRun(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'down']);
    Process::assertDidntRun(fn (PendingProcess $process): bool => $process->command === ['git', '-c', 'advice.detachedHead=false', 'checkout', '--force', 'v1.0.0']);

    $state = resolve(UpdateService::class)->state();

    expect($state['status'])->toBe('failed')
        ->and($state['step'])->toBe('Verify the v1.0.0 signature')
        ->and($state['site_down'])->toBeFalse();
});

it('refuses a trusted tag that was signed for another release', function (): void {
    Process::fake([
        '*cat-file*' => Process::result(output: "object abc1234def\ntype commit\ntag v0.7.0\n"),
        '*' => Process::result(),
    ]);

    resolve(UpdateService::class)->writeCurrentVersion('v0.9.0');

    $this->artisan(UpdateCommand::class, ['--tag' => 'v1.0.0'])
        ->expectsOutputToContain('The v1.0.0 tag was signed as v0.7.0')
        ->assertExitCode(1);

    Process::assertDidntRun(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'down']);

    expect(resolve(UpdateService::class)->state())->toMatchArray(['status' => 'failed', 'step' => 'Verify the v1.0.0 signature', 'site_down' => false]);
});

it('stops before maintenance mode when the release has to be built and Node.js is missing', function (): void {
    Process::fake([
        '*--version*' => Process::result(errorOutput: 'npm: not found', exitCode: 127),
        '*' => Process::result(),
    ]);

    resolve(UpdateService::class)->writeCurrentVersion('v0.9.0');

    $this->artisan(UpdateCommand::class, ['--tag' => 'v1.0.0'])
        ->expectsOutputToContain('npm: not found')
        ->doesntExpectOutputToContain('maintenance mode')
        ->assertExitCode(1);

    Process::assertDidntRun(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'down']);

    $state = resolve(UpdateService::class)->state();

    expect($state['step'])->toBe('Check Node.js')
        ->and($state['site_down'])->toBeFalse();
});

it('finds composer and git in the usual places when started by cron', function (): void {
    Process::fake(['*' => Process::result()]);

    resolve(UpdateService::class)->writeCurrentVersion('v0.9.0');

    $this->artisan(UpdateCommand::class, ['--tag' => 'v1.0.0'])->assertExitCode(0);

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist']
        && in_array('/usr/local/bin', explode(':', (string) ($process->environment['PATH'] ?? '')), true));
});

it('refuses to start while another update is running', function (): void {
    $running = Cache::lock('wireup:update:lock', 60);
    $running->get();

    $this->artisan(UpdateCommand::class, ['--tag' => 'v1.0.0'])
        ->expectsOutputToContain('Another update is already running.')
        ->assertExitCode(1);

    Process::assertNotRan(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'down']);

    $running->release();
});

it('does not call a long update stalled while it still holds the lock', function (): void {
    $updates = resolve(UpdateService::class);
    $updates->markRunning('v1.0.0', 'Install PHP dependencies');
    $this->travel(31)->minutes();

    $running = Cache::lock('wireup:update:lock', 3600);
    $running->get();

    expect($updates->state()['status'])->toBe('running');

    $running->release();

    expect($updates->state()['status'])->toBe('stalled');
});

it('stops before maintenance mode when the server is nearly out of disk space', function (): void {
    Process::fake(['*rev-parse*' => Process::result(output: "abc1234def\n"), '*' => Process::result()]);
    app()->instance(BackupService::class, new BackupService(resolve(DatabaseDumper::class), resolve(SiteBundle::class), fn (): int => 40 * 1024 * 1024));
    resolve(UpdateService::class)->writeCurrentVersion('v0.9.0');

    $this->artisan(UpdateCommand::class, ['--tag' => 'v1.0.0'])
        ->expectsOutputToContain('is free on the server')
        ->assertExitCode(1);

    Process::assertNotRan(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'down']);

    expect(resolve(UpdateService::class)->state())->toMatchArray(['status' => 'failed', 'step' => 'Check free disk space', 'site_down' => false]);
});

it('rolls back when the new version cannot be recorded, instead of offering the same update every day', function (): void {
    Process::fake(['*rev-parse*' => Process::result(output: "abc1234def\n"), '*' => Process::result()]);
    resolve(UpdateService::class)->writeCurrentVersion('v0.9.0');
    File::delete((string) config('wireup.version_file'));
    File::ensureDirectoryExists((string) config('wireup.version_file'));

    $this->artisan(UpdateCommand::class, ['--tag' => 'v1.0.0'])
        ->expectsOutputToContain('Rolling back')
        ->assertExitCode(1);

    expect(resolve(UpdateService::class)->state())->toMatchArray(['status' => 'failed', 'step' => 'Record the new version', 'site_down' => false]);
});
