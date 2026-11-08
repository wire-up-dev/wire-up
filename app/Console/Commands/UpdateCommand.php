<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActivityEvent;
use App\Services\ActivityLog;
use App\Services\BackupService;
use App\Services\DatabaseDumper;
use App\Services\ReleaseAssets;
use App\Services\ReleaseSignature;
use App\Services\UpdateService;
use App\Traits\RunsSteps;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Number;
use RuntimeException;

#[Description('Update Wire Up to the latest release')]
#[Signature('wireup:update {--tag= : The release tag to install} {--force : Run even when already on the target version}')]
final class UpdateCommand extends Command
{
    use RunsSteps;

    public const int MINIMUM_FREE_BYTES = 500 * 1024 * 1024;

    public function handle(UpdateService $updates, ReleaseAssets $assets, ReleaseSignature $signature, DatabaseDumper $dumper, BackupService $backups): int
    {
        $lock = $updates->updateLock();

        if (! $lock->get()) {
            $this->components->error('Another update is already running.');

            return self::FAILURE;
        }

        try {
            return $this->update($updates, $assets, $signature, $dumper, $backups);
        } finally {
            $lock->release();
        }
    }

    private function update(UpdateService $updates, ReleaseAssets $assets, ReleaseSignature $signature, DatabaseDumper $dumper, BackupService $backups): int
    {
        $tag = $this->targetTag($updates);

        if ($tag === null) {
            $this->components->error('No release tag to install.');

            return self::FAILURE;
        }

        if ($tag === $updates->currentVersion() && ! $this->option('force')) {
            $this->components->info('Already up to date.');

            return self::SUCCESS;
        }

        $this->info("Update Wire Up to {$tag}");
        $this->newLine();

        $previous = $updates->checkedOutCommit();

        if ($previous === null) {
            $this->components->error('The current version could not be read from git, so the update did not start.');

            $updates->markFailed($tag, 'Read the current version', 'git rev-parse HEAD failed.', siteIsDown: false);

            return self::FAILURE;
        }

        $prebuilt = $assets->published($tag);
        $backup = 'db-backup-'.now()->format('Y-m-d-His').'-before-'.$tag.'.'.$dumper->extension();

        $preparation = [
            'Check free disk space' => fn () => $this->ensureDiskSpace($backups),
            'Fetch releases' => ['git', 'fetch', '--tags', '--force', 'origin'],
            "Verify the {$tag} signature" => $signature->verifyTagCommand($tag),
            ...($prebuilt
                ? ['Download frontend assets' => fn (): string => $assets->download($tag)]
                : ['Check Node.js' => ['npm', '--version']]),
            'Save the current frontend assets' => $assets->snapshotBuild(...),
        ];

        foreach ($preparation as $label => $step) {
            $error = $this->runStep($updates, $tag, $label, $step);

            if ($error !== null) {
                $updates->markFailed($tag, $label, $error, siteIsDown: false);

                return self::FAILURE;
            }
        }

        $steps = [
            'Enable maintenance mode' => [PHP_BINARY, 'artisan', 'down'],
            'Back up database' => [PHP_BINARY, 'artisan', 'wireup:backup', '--name='.$backup],
            "Check out {$tag}" => ['git', '-c', 'advice.detachedHead=false', 'checkout', '--force', $tag],
            'Install PHP dependencies' => ['composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist'],
            'Run database migrations' => [PHP_BINARY, 'artisan', 'migrate', '--force'],
            ...($prebuilt
                ? ['Install frontend assets' => $assets->install(...)]
                : ['Install frontend dependencies' => ['npm', 'ci'], 'Build frontend assets' => ['npm', 'run', 'build']]),
            'Cache configuration' => [PHP_BINARY, 'artisan', 'optimize'],
            'Restart queue workers' => [PHP_BINARY, 'artisan', 'queue:restart'],
            'Record the new version' => fn () => $updates->writeCurrentVersion($tag),
        ];

        $started = [];

        foreach ($steps as $label => $step) {
            $started[] = $label;
            $error = $this->runStep($updates, $tag, $label, $step);

            if ($error !== null) {
                $version = $updates->currentVersion() ?? mb_substr($previous, 0, 7);
                $codeChanged = in_array("Check out {$tag}", $started, true);

                return $this->rollBack($updates, $tag, $version, $label, $error, [
                    ...($codeChanged ? [
                        "Check out {$version}" => ['git', '-c', 'advice.detachedHead=false', 'checkout', '--force', $previous],
                        'Install PHP dependencies' => ['composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist'],
                    ] : []),
                    ...(in_array('Run database migrations', $started, true) ? [
                        'Restore database' => [PHP_BINARY, 'artisan', 'wireup:backup:restore-database', $backup, '--force'],
                    ] : []),
                    'Restore frontend assets' => $assets->restoreSnapshot(...),
                    ...($codeChanged ? [
                        'Cache configuration' => [PHP_BINARY, 'artisan', 'optimize'],
                        'Restart queue workers' => [PHP_BINARY, 'artisan', 'queue:restart'],
                    ] : []),
                    'Disable maintenance mode' => [PHP_BINARY, 'artisan', 'up'],
                ]);
            }
        }

        $updates->check();
        $assets->discardSnapshot();

        $error = $this->runStep($updates, $tag, 'Disable maintenance mode', [PHP_BINARY, 'artisan', 'up']);

        if ($error !== null) {
            $updates->markFailed($tag, 'Disable maintenance mode', $error);

            return $this->failInMaintenanceMode();
        }

        $updates->markFinished($tag);

        ActivityLog::record(ActivityEvent::SYSTEM_UPDATED, $tag);

        $this->newLine();
        $this->info("Wire Up is now on {$tag}.");

        return self::SUCCESS;
    }

    private function ensureDiskSpace(BackupService $backups): void
    {
        $free = $backups->freeBytes();

        throw_if($free !== null && $free < self::MINIMUM_FREE_BYTES, RuntimeException::class, 'Only '.Number::fileSize($free, precision: 1).' is free on the server. An update needs at least '.Number::fileSize(self::MINIMUM_FREE_BYTES).', so it did not start.');
    }

    private function targetTag(UpdateService $updates): ?string
    {
        $option = $this->option('tag');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        return $updates->latestVersion() ?? $updates->check();
    }

    /**
     * @param  array<string, list<string>|Closure(): mixed>  $steps
     */
    private function rollBack(UpdateService $updates, string $tag, string $version, string $failedStep, string $error, array $steps): int
    {
        $this->newLine();
        $this->components->warn("Rolling back to {$version}");

        foreach ($steps as $label => $step) {
            $rollbackError = $this->runStep($updates, $tag, "Roll back: {$label}", $step);

            if ($rollbackError !== null) {
                $updates->markFailed($tag, $failedStep, $error."\n\nRolling back stopped at \"{$label}\":\n".$rollbackError);

                return $this->failInMaintenanceMode();
            }
        }

        $updates->markFailed($tag, $failedStep, $error, siteIsDown: false, rolledBackTo: $version);

        $this->newLine();
        $this->components->warn("The update failed, so Wire Up was put back on {$version}. The site is live again.");

        return self::FAILURE;
    }

    /**
     * @param  list<string>|Closure(): mixed  $step
     */
    private function runStep(UpdateService $updates, string $tag, string $label, array|Closure $step): ?string
    {
        $updates->markRunning($tag, $label);

        [$successful, $output] = $this->performStep($step);

        $this->components->task($label, fn (): bool => $successful);

        if ($successful) {
            return null;
        }

        if ($output !== '') {
            $this->components->error(mb_substr($output, -2000));
        }

        return $output;
    }

    private function failInMaintenanceMode(): int
    {
        $this->components->warn('The site is in maintenance mode. Fix the issue, then run: php artisan up');

        return self::FAILURE;
    }
}
