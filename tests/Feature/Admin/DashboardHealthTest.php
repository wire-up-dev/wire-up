<?php

declare(strict_types=1);

use App\Services\SystemHealth;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;

it('records that the scheduler ran each time it starts a task', function (): void {
    Event::dispatch(new ScheduledTaskStarting(Mockery::mock(ScheduledEvent::class)));

    expect(resolve(SystemHealth::class)->schedulerIsRunning())->toBeTrue();
});

it('warns when the scheduler has never run or stopped running', function (): void {
    $this->actingAsAdmin();

    Livewire::test('pages::admin.dashboard')
        ->assertSee('The scheduler isn’t running')
        ->assertSee('It has never run.');

    resolve(SystemHealth::class)->recordHeartbeat();
    $this->travel(11)->minutes();

    Livewire::test('pages::admin.dashboard')->assertSee('It last ran 11 minutes ago.');
});

it('stays quiet while the scheduler is running, and on a local development copy', function (): void {
    $this->actingAsAdmin();
    resolve(SystemHealth::class)->recordHeartbeat();

    Livewire::test('pages::admin.dashboard')->assertDontSee('The scheduler isn’t running');

    $this->travel(1)->hour();
    app()->detectEnvironment(fn (): string => 'local');

    Livewire::test('pages::admin.dashboard')->assertDontSee('The scheduler isn’t running');
});

it('warns when emails and webhooks have waited too long for a queue worker', function (): void {
    config(['queue.default' => 'database']);
    $this->actingAsAdmin();
    resolve(SystemHealth::class)->recordHeartbeat();

    $job = ['payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'created_at' => now()->getTimestamp()];
    DB::table('jobs')->insert([
        [...$job, 'queue' => 'default', 'available_at' => now()->subMinutes(20)->getTimestamp()],
        [...$job, 'queue' => 'default', 'available_at' => now()->subMinutes(30)->getTimestamp()],
        [...$job, 'queue' => 'default', 'available_at' => now()->subMinutes(2)->getTimestamp()],
        [...$job, 'queue' => 'system', 'available_at' => now()->subHour()->getTimestamp()],
    ]);

    Livewire::test('pages::admin.dashboard')->assertSee('2 emails and webhooks are waiting');
});

it('ignores waiting jobs when the queue does not live in the database', function (): void {
    config(['queue.default' => 'sync']);

    expect(resolve(SystemHealth::class)->waitingJobs())->toBe(0);
});

it('points developers to the errors when background tasks failed this week', function (): void {
    $this->actingAsAdmin();
    resolve(SystemHealth::class)->recordHeartbeat();

    $failed = ['connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'RuntimeException'];
    DB::table('failed_jobs')->insert([
        [...$failed, 'uuid' => (string) Str::uuid(), 'failed_at' => now()->subDay()],
        [...$failed, 'uuid' => (string) Str::uuid(), 'failed_at' => now()->subWeeks(2)],
    ]);

    Livewire::test('pages::admin.dashboard')->assertSee('1 background task failed this week');
});
