<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActivityEvent;
use App\Services\ActivityLog;
use App\Services\ReleaseAssets;
use App\Services\UpdateService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Foundation\Events\MaintenanceModeDisabled;
use Illuminate\Support\Facades\File;
use Throwable;

#[Description('Bring the site back up after an update and record that it finished')]
#[Signature('wireup:update:complete {tag : The release tag that was installed}')]
final class CompleteUpdateCommand extends Command
{
    public function handle(UpdateService $updates, ReleaseAssets $assets): int
    {
        $tag = (string) $this->argument('tag');

        try {
            $this->laravel->maintenanceMode()->deactivate();
            File::delete(storage_path('framework/maintenance.php'));
        } catch (Throwable $exception) {
            $updates->markFailed($tag, 'Disable maintenance mode', $exception->getMessage());
            $this->components->error('The site could not leave maintenance mode: '.$exception->getMessage());

            return self::FAILURE;
        }

        event(new MaintenanceModeDisabled);

        $updates->markFinished($tag);

        ActivityLog::record(ActivityEvent::SYSTEM_UPDATED, $tag);

        $assets->discardSnapshot();
        $updates->check();

        $this->components->info("Wire Up is now on {$tag}.");

        return self::SUCCESS;
    }
}
