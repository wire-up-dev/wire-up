<?php

declare(strict_types=1);

use App\Services\UpdateService;

it('shows a note instead of an error box when the admin stops answering during an update', function (): void {
    $this->actingAsAdmin();
    resolve(UpdateService::class)->markRunning('v1.0.0', 'Install PHP dependencies');

    $page = visit(route('admin.settings-updates'));

    $page->assertSee('Install PHP dependencies')
        ->assertScript("document.querySelector('[data-update-interrupted]').classList.contains('hidden')", true);

    app()->bind(UpdateService::class, fn (): never => throw new RuntimeException('The update is switching versions'));

    $page->wait(5)
        ->assertScript("document.querySelector('[data-update-interrupted]').classList.contains('hidden')", false)
        ->assertScript("document.getElementById('livewire-error') === null", true)
        ->assertSee('The admin is switching to the new version');
});
