<?php

declare(strict_types=1);

use App\Models\Locale;
use App\Models\Role;
use App\Models\Settings;
use App\Models\User;
use Livewire\Livewire;

function activateDutch(): void
{
    Locale::query()->where('code', 'nl')->update(['active' => true]);
    cache()->forget('site-locales');
}

it('opens the cookie consent screen from the site settings tabs', function (): void {
    $this->actingAsAdmin()
        ->get(route('admin.settings-cookie-consent'))
        ->assertOk()
        ->assertSeeLivewire('pages::admin.settings-cookie-consent')
        ->assertSee(route('admin.settings-identity'), false);
});

it('starts switched off with the default wording as placeholders', function (): void {
    $this->actingAsAdmin();

    Livewire::test('pages::admin.settings-cookie-consent')
        ->assertSet('enabled', false)
        ->assertSet('message', [])
        ->assertSee('We use cookies to understand how visitors use this site.')
        ->assertSee('Cookie settings');
});

it('saves the switch and the wording for each language', function (): void {
    activateDutch();
    $this->actingAsAdmin();

    Livewire::test('pages::admin.settings-cookie-consent')
        ->set('enabled', true)
        ->set('message.en', '<p>We count visits. <a href="/privacy">Privacy</a></p>')
        ->set('message.nl', '<p>Wij tellen bezoeken.</p>')
        ->set('accept.nl', 'Akkoord')
        ->set('decline.nl', 'Weigeren')
        ->set('link.en', 'Cookies')
        ->call('update')
        ->assertHasNoErrors();

    expect(Settings::get('cookie_consent_enabled'))->toBeTrue()
        ->and(Settings::get('cookie_consent_message'))->toBe([
            'en' => '<p>We count visits. <a href="/privacy">Privacy</a></p>',
            'nl' => '<p>Wij tellen bezoeken.</p>',
        ])
        ->and(Settings::get('cookie_consent_accept'))->toBe(['nl' => 'Akkoord'])
        ->and(Settings::get('cookie_consent_decline'))->toBe(['nl' => 'Weigeren'])
        ->and(Settings::get('cookie_consent_link'))->toBe(['en' => 'Cookies']);
});

it('loads the saved wording back into the form', function (): void {
    Settings::set([
        'cookie_consent_enabled' => true,
        'cookie_consent_message' => ['en' => '<p>Hello</p>', 'nl' => '', 'xx' => 5],
        'cookie_consent_accept' => 'not a map',
    ]);
    $this->actingAsAdmin();

    Livewire::test('pages::admin.settings-cookie-consent')
        ->assertSet('enabled', true)
        ->assertSet('message', ['en' => '<p>Hello</p>'])
        ->assertSet('accept', []);
});

it('strips scripts from the message and drops empty editor paragraphs', function (): void {
    $this->actingAsAdmin();

    Livewire::test('pages::admin.settings-cookie-consent')
        ->set('message.en', '<p>Hi<script>alert(1)</script></p>')
        ->set('accept.en', '   ')
        ->call('update')
        ->assertHasNoErrors();

    expect(Settings::get('cookie_consent_message'))->toBe(['en' => '<p>Hi</p>'])
        ->and(Settings::get('cookie_consent_accept'))->toBe([]);

    Livewire::test('pages::admin.settings-cookie-consent')
        ->set('message.en', '<p></p>')
        ->call('update');

    expect(Settings::get('cookie_consent_message'))->toBe([]);
});

it('explains a label that is too long and jumps to its language', function (): void {
    activateDutch();
    $this->actingAsAdmin();

    Livewire::test('pages::admin.settings-cookie-consent')
        ->set('accept.nl', str_repeat('a', 41))
        ->call('update')
        ->assertHasErrors(['accept.nl' => 'max'])
        ->assertSet('locale', 'nl')
        ->assertSee('The Accept button may not exceed 40 characters.');
});

it('cycles through the active languages and keeps the one from the address', function (): void {
    activateDutch();
    $this->actingAsAdmin();

    Livewire::test('pages::admin.settings-cookie-consent')
        ->assertSet('locale', 'en')
        ->call('changeLocale')
        ->assertSet('locale', 'nl')
        ->call('changeLocale')
        ->assertSet('locale', 'en');

    Livewire::withQueryParams(['locale' => 'nl'])
        ->test('pages::admin.settings-cookie-consent')
        ->assertSet('locale', 'nl');
});

it('lets someone who can only view settings look but not save', function (): void {
    $role = Role::query()->create(['key' => 'settings-viewer', 'name' => 'Viewer', 'abilities' => ['settings.view'], 'bypass' => false, 'is_protected' => false]);

    $this->actingAs(User::factory()->role($role)->create(['active' => true]));

    Livewire::test('pages::admin.settings-cookie-consent')
        ->set('enabled', true)
        ->call('update')
        ->assertForbidden();

    expect(Settings::get('cookie_consent_enabled'))->toBeNull();
});
