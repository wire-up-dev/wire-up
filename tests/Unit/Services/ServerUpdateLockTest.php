<?php

declare(strict_types=1);

use App\Services\ServerUpdateLock;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->directory = storage_path('framework/testing/server-lock-'.Str::random(8));
    File::ensureDirectoryExists($this->directory);
    config()->set('wireup.server_update_lock', $this->directory.'/wireup-update.lock');
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

it('lets only one update on the server hold the lock at a time', function (): void {
    $first = new ServerUpdateLock();
    $second = new ServerUpdateLock();

    expect($first->acquire())->toBeTrue()
        ->and($second->acquire())->toBeFalse();

    $first->release();

    expect($second->acquire())->toBeTrue();

    $second->release();
    $second->release();
});

it('does not hold updates back when the lock file cannot be created', function (): void {
    config()->set('wireup.server_update_lock', $this->directory.'/missing/wireup-update.lock');

    expect(new ServerUpdateLock()->acquire())->toBeTrue();
});

it('shares the lock with sites that run as another user', function (): void {
    $path = $this->directory.'/wireup-update.lock';
    $first = new ServerUpdateLock();

    expect($first->acquire())->toBeTrue()
        ->and(decoct(fileperms($path) & 0777))->toBe('666');

    $first->release();
    chmod($path, 0444);

    $readOnly = new ServerUpdateLock();
    $other = new ServerUpdateLock();

    expect($readOnly->acquire())->toBeTrue()
        ->and($other->acquire())->toBeFalse();

    $readOnly->release();
    chmod($path, 0666);
});
