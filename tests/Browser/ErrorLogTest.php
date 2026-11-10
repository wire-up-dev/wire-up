<?php

declare(strict_types=1);

use App\Models\ErrorLog;
use App\Models\User;

it('copies an error as an AI prompt from its details', function (): void {
    $error = ErrorLog::factory()->create(['message' => 'Route [admin.settings-remote-mcp] not defined.']);
    $this->actingAs(User::factory()->developer()->create(['active' => true]));

    $page = visit(route('admin.settings-errors', ['error' => $error->id]));

    $page->assertSee('Copy for AI')
        ->script("window.copiedText = null; Object.defineProperty(navigator, 'clipboard', { value: { writeText: (text) => { window.copiedText = text; return Promise.resolve(); } } }); void 0");

    $page->click('Copy for AI')
        ->assertSee('Copied')
        ->assertScript("window.copiedText.startsWith('This error happened on a Wire Up site')", true)
        ->assertScript("window.copiedText.includes('Message: Route [admin.settings-remote-mcp] not defined.')", true)
        ->assertNoJavaScriptErrors();
});
