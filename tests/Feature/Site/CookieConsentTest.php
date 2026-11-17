<?php

declare(strict_types=1);

use App\Models\Settings;
use Livewire\Livewire;

beforeEach(function (): void {
    Settings::set([
        'google_analytics_id' => 'G-TEST123',
        'head_scripts' => '<script>window.headMarker=1</script>',
        'body_scripts' => '<script>window.bodyMarker=1</script>',
    ]);
});

it('loads analytics and custom code straight away while consent is off', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('googletagmanager.com/gtag/js?id=G-TEST123', false)
        ->assertSee('<script>window.headMarker=1</script>', false)
        ->assertSee('<script>window.bodyMarker=1</script>', false)
        ->assertDontSee('data-consent=', false)
        ->assertDontSee('data-cookie-consent', false)
        ->assertDontSee('data-cookie-settings', false);
});

it('holds analytics and custom code back until the visitor accepts', function (): void {
    Settings::set(['cookie_consent_enabled' => true]);

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)
        ->toMatch('/<template data-consent="head">.*googletagmanager\.com\/gtag\/js\?id=G-TEST123.*window\.headMarker=1.*<\/template>/s')
        ->toMatch('/<template data-consent="body"><script>window\.bodyMarker=1<\/script><\/template>/')
        ->toContain('data-cookie-consent', 'We use cookies to understand how visitors use this site.', 'wireupConsent.accept()', 'wireupConsent.decline()');
});

it('shows the owner\'s wording in the visitor\'s language', function (): void {
    Settings::set([
        'cookie_consent_enabled' => true,
        'cookie_consent_message' => ['en' => '<p>We count visits.</p>', 'nl' => '<p>Wij tellen bezoeken.</p>'],
        'cookie_consent_accept' => ['en' => 'OK', 'nl' => 'Akkoord'],
        'cookie_consent_decline' => ['en' => 'No thanks'],
        'cookie_consent_link' => ['nl' => 'Cookie-instellingen'],
    ]);
    app()->setLocale('nl');

    expect((string) $this->blade('<x-site.cookie-consent />'))
        ->toContain('<p>Wij tellen bezoeken.</p>', 'Akkoord', 'No thanks')
        ->not->toContain('We count visits.');

    Livewire::test('site.footer')->assertSee('Cookie-instellingen');
});

it('offers the cookie settings link in every footer layout', function (string $layout): void {
    Settings::set(['cookie_consent_enabled' => true, 'footer_layout' => $layout]);

    Livewire::test('site.footer')
        ->assertSeeHtml('data-cookie-settings')
        ->assertSee('Cookie settings');
})->with(['default', 'centered', 'columns', 'minimal']);
