<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Integrations\IntegrationSettings;
use App\Models\Settings;
use Illuminate\Mail\MailManager;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Once;

final class RefreshSettingsBeforeEachJob
{
    public function handle(JobProcessing $event): void
    {
        if ($event->connectionName === 'sync') {
            return;
        }

        config()->set('site', Settings::cached());
        new IntegrationSettings()->apply();
        resolve(MailManager::class)->forgetMailers();
        Once::flush();
    }
}
