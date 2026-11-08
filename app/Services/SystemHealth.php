<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SystemHealth
{
    public const string HEARTBEAT = 'wireup:scheduler:heartbeat';

    public const int SCHEDULER_GRACE_MINUTES = 10;

    public const int WAITING_JOB_MINUTES = 15;

    public function recordHeartbeat(): void
    {
        Cache::forever(self::HEARTBEAT, now()->toIso8601String());
    }

    public function schedulerLastRan(): ?CarbonImmutable
    {
        $heartbeat = Cache::get(self::HEARTBEAT);

        return is_string($heartbeat) ? CarbonImmutable::parse($heartbeat) : null;
    }

    public function schedulerIsRunning(): bool
    {
        return $this->schedulerLastRan()?->gt(now()->subMinutes(self::SCHEDULER_GRACE_MINUTES)) ?? false;
    }

    public function waitingJobs(): int
    {
        if (config('queue.default') !== 'database') {
            return 0;
        }

        return DB::table(config()->string('queue.connections.database.table', 'jobs'))
            ->where('queue', '!=', 'system')
            ->where('available_at', '<', now()->subMinutes(self::WAITING_JOB_MINUTES)->getTimestamp())
            ->count();
    }

    public function failedJobsThisWeek(): int
    {
        $table = config()->string('queue.failed.table', 'failed_jobs');

        return Schema::hasTable($table) ? DB::table($table)->where('failed_at', '>', now()->subWeek())->count() : 0;
    }
}
