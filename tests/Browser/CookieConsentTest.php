<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Models\Page;
use App\Models\Settings;

beforeEach(function (): void {
    $home = Page::factory()->create([
        'metadata' => ['published_locales' => ['en']],
        'status' => ContentStatus::PUBLISHED,
        'published_at' => now()->subDay(),
        'title' => 'Consent Home',
    ]);
    $home->slugs()->create(['locale' => 'en', 'slug' => 'consent-home']);

    Settings::set([
        'home_page_id' => $home->id,
        'cookie_consent_enabled' => true,
        'head_scripts' => '<script>window.headMarker = (window.headMarker || 0) + 1</script>',
        'body_scripts' => '<script>window.bodyMarker = (window.bodyMarker || 0) + 1</script>',
    ]);
});

it('runs the custom code only once the visitor accepts, and remembers it', function (): void {
    $page = visit('/');

    $page->assertNoJavascriptErrors()
        ->assertVisible('[data-cookie-consent]')
        ->assertScript('window.headMarker === undefined && window.bodyMarker === undefined', true)
        ->click('Accept')
        ->assertMissing('[data-cookie-consent]')
        ->assertScript('window.headMarker', 1)
        ->assertScript('window.bodyMarker', 1);

    $page->refresh()
        ->assertMissing('[data-cookie-consent]')
        ->assertScript('window.headMarker', 1)
        ->assertScript('window.bodyMarker', 1);
});

it('keeps the custom code out when the visitor declines', function (): void {
    $page = visit('/');

    $page->click('Decline')
        ->assertMissing('[data-cookie-consent]')
        ->refresh()
        ->assertMissing('[data-cookie-consent]')
        ->assertScript('window.headMarker === undefined && window.bodyMarker === undefined', true)
        ->assertNoJavascriptErrors();
});

it('reopens the banner from the footer so the visitor can change their mind', function (): void {
    $page = visit('/');

    $page->click('Decline')
        ->assertMissing('[data-cookie-consent]')
        ->click('Cookie settings')
        ->assertVisible('[data-cookie-consent]')
        ->click('Accept')
        ->assertScript('window.headMarker', 1);
});
