<?php

declare(strict_types=1);

use App\Console\Commands\CheckForUpdatesCommand;
use App\Console\Commands\CleanTempUploadsCommand;
use App\Console\Commands\CreateBackupCommand;
use App\Console\Commands\PruneAbandonedOrdersCommand;
use App\Console\Commands\PruneImageCacheCommand;
use App\Console\Commands\PruneStaleDataCommand;
use App\Console\Commands\ReconcileOrdersCommand;
use App\Console\Commands\SendBookingRemindersCommand;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\ErrorLog;
use App\Models\NotFoundPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

$minute = sprintf('%02d', crc32(base_path()) % 60);

Schedule::command(CleanTempUploadsCommand::class)
    ->dailyAt("00:{$minute}")
    ->withoutOverlapping(120);

Schedule::command(PruneImageCacheCommand::class)
    ->dailyAt("00:{$minute}")
    ->withoutOverlapping(120);

Schedule::command(CheckForUpdatesCommand::class)
    ->dailyAt("00:{$minute}")
    ->withoutOverlapping(120);

Schedule::command('queue:work --queue=system --stop-when-empty --tries=1')
    ->everyMinute()
    ->when(fn (): bool => config('queue.default') !== 'database' || DB::table('jobs')->where('queue', 'system')->exists())
    ->withoutOverlapping(70)
    ->runInBackground();

Schedule::command(ReconcileOrdersCommand::class)
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command(SendBookingRemindersCommand::class)
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command(PruneAbandonedOrdersCommand::class)
    ->dailyAt("00:{$minute}")
    ->withoutOverlapping(120);

Schedule::command('model:prune', ['--model' => [NotFoundPath::class, Activity::class, ErrorLog::class, Booking::class]])
    ->dailyAt("00:{$minute}")
    ->withoutOverlapping(120);

Schedule::command(PruneStaleDataCommand::class)
    ->dailyAt("00:{$minute}")
    ->withoutOverlapping(120);

Schedule::command('queue:prune-failed', ['--hours' => 720])
    ->dailyAt("00:{$minute}")
    ->withoutOverlapping(120);

Schedule::command(CreateBackupCommand::class, ['--scheduled'])
    ->dailyAt("03:{$minute}")
    ->withoutOverlapping(180);
