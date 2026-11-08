<?php

declare(strict_types=1);

namespace App\Providers;

use App\Integrations\IntegrationSettings;
use Illuminate\Support\ServiceProvider;

final class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        new IntegrationSettings()->apply();
    }
}
