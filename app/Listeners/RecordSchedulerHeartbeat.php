<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\SystemHealth;

final readonly class RecordSchedulerHeartbeat
{
    public function __construct(private SystemHealth $health) {}

    public function handle(): void
    {
        $this->health->recordHeartbeat();
    }
}
