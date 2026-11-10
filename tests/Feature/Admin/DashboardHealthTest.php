<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\SystemHealth;
use Carbon\CarbonInterface;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;

function healthDeveloper(): User
{
    $developer = User::factory()->developer()->create(['active' => true]);
    test()->actingAs($developer);

    return $developer;
}

/**
 * @param  list<CarbonInterface>  $times
 */
function failBackgroundTasks(array $times): void
{
    foreach ($times as $time) {
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'RuntimeException', 'failed_at' => $time]);
    }
}

it('records that the scheduler ran each time it starts a task', function (): void {
    Event::dispatch(new ScheduledTaskStarting(Mockery::mock(ScheduledEvent::class)));

    expect(resolve(SystemHealth::class)->schedulerIsRunning())->toBeTrue();
});

it('warns when the scheduler has never run or stopped running', function (): void {
    healthDeveloper();

    Livewire::test('pages::admin.dashboard')
        ->assertSee('The scheduler isn’t running')
        ->assertSee('It has never run.');

    resolve(SystemHealth::class)->recordHeartbeat();
    $this->travel(11)->minutes();

    Livewire::test('pages::admin.dashboard')->assertSee('It last ran 11 minutes ago.');
});

it('stays quiet while the scheduler is running, and on a local development copy', function (): void {
    healthDeveloper();
    resolve(SystemHealth::class)->recordHeartbeat();

    Livewire::test('pages::admin.dashboard')->assertDontSee('The scheduler isn’t running');

    $this->travel(1)->hour();
    app()->detectEnvironment(fn (): string => 'local');

    Livewire::test('pages::admin.dashboard')->assertDontSee('The scheduler isn’t running');
});

it('warns when emails and webhooks have waited too long for a queue worker', function (): void {
    config(['queue.default' => 'database']);
    healthDeveloper();
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
    healthDeveloper();
    resolve(SystemHealth::class)->recordHeartbeat();
    failBackgroundTasks([now()->subDay(), now()->subWeeks(2)]);

    Livewire::test('pages::admin.dashboard')
        ->assertSee('1 background task failed this week')
        ->assertSee(route('admin.settings-errors'));
});

it('lets a developer dismiss failed tasks until another one fails', function (): void {
    healthDeveloper();
    resolve(SystemHealth::class)->recordHeartbeat();
    failBackgroundTasks([now()->subDay()]);

    Livewire::test('pages::admin.dashboard')
        ->assertSee('1 background task failed this week')
        ->call('dismissFailedJobs')
        ->assertDontSee('background task failed');

    $this->travel(1)->minute();
    failBackgroundTasks([now()]);

    Livewire::test('pages::admin.dashboard')->assertSee('1 background task failed this week');

    expect(DB::table('failed_jobs')->count())->toBe(2);
});

it('keeps server warnings away from site owners, who cannot act on them', function (): void {
    config(['queue.default' => 'database']);
    $this->actingAsAdmin();
    failBackgroundTasks([now()->subDay()]);

    Livewire::test('pages::admin.dashboard')
        ->assertDontSee('The scheduler isn’t running')
        ->assertDontSee('background task failed')
        ->call('dismissFailedJobs')
        ->assertForbidden();
});
