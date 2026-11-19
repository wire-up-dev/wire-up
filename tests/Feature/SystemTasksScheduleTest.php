<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

it('starts updates, backups and imports from the scheduler every minute, one run at a time', function (): void {
    $this->artisan('schedule:list')->assertExitCode(0);

    $event = collect(resolve(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'queue:work --queue=system --stop-when-empty --tries=1'));

    expect($event?->expression)->toBe('* * * * *')
        ->and($event?->withoutOverlapping)->toBeTrue()
        ->and($event?->expiresAt)->toBe(70)
        ->and($event?->runInBackground)->toBeTrue();
});

it('only starts the system worker when there is system work waiting', function (): void {
    config()->set('queue.default', 'database');
    $event = collect(resolve(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'queue:work --queue=system'));

    expect($event?->filtersPass(app()))->toBeFalse();

    DB::table('jobs')->insert(['queue' => 'system', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()]);

    expect($event?->filtersPass(app()))->toBeTrue();

    config()->set('queue.default', 'redis');
    DB::table('jobs')->delete();

    expect($event?->filtersPass(app()))->toBeTrue();
});

it('gives every repeating task a lock that clears within its own interval', function (): void {
    $locks = collect(resolve(Schedule::class)->events())
        ->filter(fn (Event $event): bool => $event->withoutOverlapping)
        ->mapWithKeys(fn (Event $event): array => [$event->expression => $event->expiresAt]);

    expect($locks->get('*/5 * * * *'))->toBe(10)
        ->and($locks->get((crc32(base_path()) % 60).' 0 * * *'))->toBe(120);
});
