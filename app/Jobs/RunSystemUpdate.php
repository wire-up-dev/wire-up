<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\UpdateService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Process;
use Throwable;

#[Queue('system')]
#[Timeout(UpdateService::TIME_LIMIT + 600)]
#[Tries(1)]
final class RunSystemUpdate implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $tag) {}

    public function uniqueId(): string
    {
        return 'wireup-update';
    }

    public function handle(UpdateService $updates): void
    {
        $state = $updates->state();

        if ($state['status'] !== 'pending' || $state['tag'] !== $this->tag) {
            return;
        }

        $result = Process::path(base_path())
            ->timeout(UpdateService::TIME_LIMIT)
            ->run([PHP_BINARY, 'artisan', 'wireup:update', '--tag='.$this->tag, '--force']);

        if ($result->failed() && ! $this->alreadyHandled($updates)) {
            $this->markFailed($updates, mb_trim($result->output()."\n".$result->errorOutput()));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $updates = resolve(UpdateService::class);

        if ($updates->currentVersion() === $this->tag || $this->alreadyHandled($updates)) {
            return;
        }

        $this->markFailed($updates, (string) $exception?->getMessage());
    }

    private function alreadyHandled(UpdateService $updates): bool
    {
        return $updates->state()['status'] === 'failed' || $updates->updateIsLocked();
    }

    private function markFailed(UpdateService $updates, string $output): void
    {
        $state = $updates->state();

        $updates->markFailed($this->tag, $state['status'] === 'running' ? (string) $state['step'] : 'update', $output, siteIsDown: app()->isDownForMaintenance());
    }
}
