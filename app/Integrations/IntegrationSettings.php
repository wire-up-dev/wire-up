<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Models\Settings;

final class IntegrationSettings
{
    public static function configIsBeingCached(): bool
    {
        return app()->runningConsoleCommand('config:cache', 'optimize');
    }

    public function apply(): void
    {
        if (self::configIsBeingCached()) {
            return;
        }

        config()->set($this->environmentValues());

        $saved = Settings::uncached(IntegrationRegistry::settingKeys());

        foreach (IntegrationRegistry::all() as $integration) {
            config()->set($integration->config($saved));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function environmentValues(): array
    {
        $saved = config('integrations.environment');

        if (is_array($saved)) {
            return $saved;
        }

        $environment = [];

        foreach (IntegrationRegistry::configKeys() as $configKey) {
            $environment[$configKey] = config($configKey);
        }

        config()->set('integrations.environment', $environment);

        return $environment;
    }
}
