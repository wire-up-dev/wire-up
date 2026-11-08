<?php

declare(strict_types=1);

use App\Models\Settings;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;

function processQueuedJobOn(string $connection): void
{
    $job = Mockery::mock(Job::class);
    $job->allows('payload')->andReturn([]);

    Event::dispatch(new JobProcessing($connection, $job));
}

function memoisedCallCount(): int
{
    return once(fn (): int => $GLOBALS['memoisedCalls'] = ($GLOBALS['memoisedCalls'] ?? 0) + 1);
}

it('gives a long-running queue worker the settings saved since it started', function (): void {
    Settings::set(['site_name' => 'Saved in the admin']);
    config(['site.site_name' => 'Booted with the worker']);
    $before = memoisedCallCount();

    processQueuedJobOn('database');

    expect(config('site.site_name'))->toBe('Saved in the admin')
        ->and(memoisedCallCount())->toBe($before + 1);
});

it('leaves a job running inside the request alone', function (): void {
    config(['site.site_name' => 'Set for this request']);
    $before = memoisedCallCount();

    processQueuedJobOn('sync');

    expect(config('site.site_name'))->toBe('Set for this request')
        ->and(memoisedCallCount())->toBe($before);
});
