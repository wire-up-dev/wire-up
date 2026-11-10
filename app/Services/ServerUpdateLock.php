<?php

declare(strict_types=1);

namespace App\Services;

final class ServerUpdateLock
{
    /**
     * @var resource|null
     */
    private mixed $handle = null;

    public function acquire(): bool
    {
        $handle = rescue(fn (): mixed => fopen((string) config('wireup.server_update_lock'), 'c'), false, report: false);

        if (! is_resource($handle)) {
            return true;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }

        $this->handle = null;
    }
}
