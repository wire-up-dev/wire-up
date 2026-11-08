<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\Mail;
use App\Models\Page;
use App\Models\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Number;

final class SettingsService
{
    /**
     * @var array<int, string>
     */
    public const array BUILTIN_MENUS = ['header', 'footer'];

    public const string AUTH_LINK_PREFIX = 'auth:';

    /**
     * @var array<string, string>
     */
    private const array REGION_CURRENCY = [
        'US' => 'USD', 'GB' => 'GBP', 'CA' => 'CAD', 'AU' => 'AUD', 'NZ' => 'NZD',
        'CH' => 'CHF', 'JP' => 'JPY', 'CN' => 'CNY', 'HK' => 'HKD', 'SG' => 'SGD',
        'IN' => 'INR', 'ID' => 'IDR', 'MY' => 'MYR', 'TH' => 'THB', 'VN' => 'VND',
        'KR' => 'KRW', 'PH' => 'PHP', 'TR' => 'TRY', 'RU' => 'RUB', 'UA' => 'UAH',
        'PL' => 'PLN', 'CZ' => 'CZK', 'HU' => 'HUF', 'RO' => 'RON', 'BG' => 'BGN',
        'DK' => 'DKK', 'NO' => 'NOK', 'SE' => 'SEK', 'IL' => 'ILS', 'SA' => 'SAR',
        'AE' => 'AED', 'ZA' => 'ZAR', 'MX' => 'MXN', 'BR' => 'BRL', 'NG' => 'NGN',
        'DE' => 'EUR', 'FR' => 'EUR', 'ES' => 'EUR', 'IT' => 'EUR', 'NL' => 'EUR',
        'PT' => 'EUR', 'GR' => 'EUR', 'FI' => 'EUR', 'IE' => 'EUR', 'AT' => 'EUR',
        'BE' => 'EUR', 'EE' => 'EUR', 'LV' => 'EUR', 'LT' => 'EUR', 'SK' => 'EUR',
        'SI' => 'EUR', 'HR' => 'EUR',
    ];

    public static function current(): self
    {
        return once(fn (): self => new self);
    }

    /**
     * @return array<int, array{key: string, name: string, builtin: bool, display: array{background: bool, position: string, sticky: bool, mobile: string}, items: array<string, array<int, mixed>>}>
     */
    public static function normalizeMenus(mixed $stored): array
    {
        $byKey = [];

        if (is_array($stored)) {
            foreach ($stored as $menu) {
                $key = is_array($menu) && is_string($menu['key'] ?? null) ? $menu['key'] : '';

                if ($key === '') {
                    continue;
                }

                $byKey[$key] = [
                    'key' => $key,
                    'name' => is_string($menu['name'] ?? null) && $menu['name'] !== '' ? $menu['name'] : ucfirst($key),
                    'builtin' => in_array($key, self::BUILTIN_MENUS, true),
                    'display' => self::normalizeMenuDisplay($menu['display'] ?? null),
                    'items' => is_array($menu['items'] ?? null) ? $menu['items'] : [],
                ];
            }
        }

        $ordered = [];

        foreach (self::BUILTIN_MENUS as $builtin) {
            $ordered[] = $byKey[$builtin] ?? self::emptyBuiltinMenu($builtin);

            unset($byKey[$builtin]);
        }

        return [...$ordered, ...array_values($byKey)];
    }

    /**
     * @return array{background: bool, position: string, sticky: bool, mobile: string}
     */
    public static function normalizeMenuDisplay(mixed $display): array
    {
        $display = is_array($display) ? $display : [];

        return [
            'background' => (bool) ($display['background'] ?? true),
            'position' => ($display['position'] ?? null) === 'right' ? 'right' : 'left',
            'sticky' => (bool) ($display['sticky'] ?? false),
            'mobile' => in_array($display['mobile'] ?? null, ['collapse', 'hide', 'toggle'], true) ? $display['mobile'] : 'collapse',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{heading: string, items: array<int, array<string, mixed>>}>
     */
    public static function groupMenuItems(array $items): array
    {
        $groups = [];
        $current = ['heading' => '', 'items' => []];

        foreach ($items as $item) {
            if (($item['type'] ?? 'link') === 'heading') {
                if ($current['heading'] !== '' || $current['items'] !== []) {
                    $groups[] = $current;
                }

                $current = ['heading' => is_string($item['label'] ?? null) ? $item['label'] : '', 'items' => []];

                continue;
            }

            $current['items'][] = $item;
        }

        if ($current['heading'] !== '' || $current['items'] !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    public static function deduceCurrency(): string
    {
        $localization = resolve('localization');
        $regional = $localization->getActiveLocales()[$localization->getDefaultLocale()]['regional'] ?? null;
        $region = is_string($regional) ? str($regional)->after('-')->upper()->value() : '';

        return self::REGION_CURRENCY[$region] ?? 'USD';
    }

    /**
     * @return array{display: array{background: bool, position: string, sticky: bool, mobile: string}, items: array<int, array{type: string, label: string, url: string, target: string, appearance: string, icon: ?string, icon_svg: string, badge: string, badgeColor: string}>}|null
     */
    public function menuForDisplay(string $key): ?array
    {
        return new MenuResolver($this)->menuForDisplay($key);
    }

    /**
     * @return array<int, array{type: string, label: string, url: string, target: string, appearance: string, icon: ?string, icon_svg: string, badge: string, badgeColor: string}>
     */
    public function menu(string $key): array
    {
        return new MenuResolver($this)->menu($key);
    }

    /**
     * @return array<int, array{key: string, name: string, builtin: bool, display: array{background: bool, position: string, sticky: bool, mobile: string}, items: array<string, array<int, mixed>>}>
     */
    public function allMenus(): array
    {
        return self::normalizeMenus(config('site.menus'));
    }

    public function homePage(bool $publishedOnly = true): ?Page
    {
        return $publishedOnly ? once(fn (): ?Page => $this->resolveHomePage(true)) : $this->resolveHomePage(false);
    }

    public function homePageId(): ?int
    {
        return $this->homePage()?->id;
    }

    public function title(): string
    {
        return $this->localeValue(config('site.title'));
    }

    public function description(): string
    {
        return $this->localeValue(config('site.description'));
    }

    public function mailConfigured(): bool
    {
        if ($this->smtpSettingsComplete()) {
            return true;
        }

        return ! in_array(config('mail.default'), ['log', 'null', null, ''], true);
    }

    public function smtpSettingsComplete(): bool
    {
        $site = config('site');

        return new Mail()->complete(is_array($site) ? $site : []);
    }

    /**
     * @return array<int, array{name: string, title: string, description: string, instructions: string}>
     */
    public function assistantGuides(): array
    {
        $saved = config('site.assistant_guides');
        $guides = [];

        foreach (is_array($saved) ? $saved : [] as $guide) {
            if (is_array($guide) && is_string($guide['name'] ?? null) && is_string($guide['description'] ?? null) && is_string($guide['instructions'] ?? null) && $guide['name'] !== '' && $guide['description'] !== '') {
                $guides[] = [
                    'name' => $guide['name'],
                    'title' => is_string($guide['title'] ?? null) && $guide['title'] !== '' ? $guide['title'] : $guide['name'],
                    'description' => $guide['description'],
                    'instructions' => $guide['instructions'],
                ];
            }
        }

        return $guides;
    }

    public function contactEmail(): string
    {
        $email = config('site.contact_email');

        return is_string($email) ? $email : '';
    }

    public function noindex(): bool
    {
        return (bool) config('site.noindex', false);
    }

    public function allowsRegistration(): bool
    {
        return (bool) config('site.allow_registration', false);
    }

    public function cachesPages(): bool
    {
        return (bool) config('site.page_cache', true);
    }

    public function backupsEnabled(): bool
    {
        return (bool) config('site.backups_enabled', false);
    }

    public function remoteMcpEnabled(): bool
    {
        return (bool) config('site.remote_mcp_enabled', false);
    }

    public function backupFrequency(): string
    {
        return config('site.backup_frequency') === 'weekly' ? 'weekly' : 'daily';
    }

    public function backupsToKeep(): int
    {
        $keep = config('site.backups_to_keep');

        return is_numeric($keep) ? max(1, min(30, (int) $keep)) : 5;
    }

    public function offsiteBackupsToKeep(): int
    {
        $keep = config('site.offsite_backups_to_keep');

        return is_numeric($keep) ? max(1, min(100, (int) $keep)) : 14;
    }

    public function backupIncludesFiles(): bool
    {
        return (bool) config('site.backup_include_files', true);
    }

    public function requiresTwoFactor(): bool
    {
        return (bool) config('site.require_two_factor', false);
    }

    /**
     * @return array<int, string>
     */
    public function interfaceTranslationLocales(): array
    {
        $default = resolve('localization')->getDefaultLocale();

        return resolve('localization')->getActiveLocaleCodes()
            ->sortBy(fn (string $code): int => $code === $default ? 1 : 0)
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function authPageOptions(): array
    {
        $options = ['auth:login' => __('Login')];

        if ($this->allowsRegistration()) {
            $options['auth:register'] = __('Register');
        }

        return $options;
    }

    public function resolveAuthLink(string $value): ?string
    {
        return match ($value) {
            'auth:login' => route('login'),
            'auth:register' => $this->allowsRegistration() ? route('register') : null,
            default => null,
        };
    }

    public function authLayout(): string
    {
        $layout = config('site.auth_layout');

        if (is_string($layout) && config()->has('theme.auth_layouts.'.$layout)) {
            return $layout;
        }

        return config()->string('theme.default_auth_layout');
    }

    public function authImageUrl(string $crop = 'default'): ?string
    {
        $item = config('site.auth_image');

        return $this->imageUrl(is_array($item) ? $item : null, $crop, ['w' => 1200, 'q' => 80, 'fm' => 'webp']);
    }

    public function authImageSide(): string
    {
        return config('site.auth_image_side') === 'right' ? 'right' : 'left';
    }

    public function currency(): string
    {
        $code = config('site.currency');

        if (is_string($code) && $code !== '' && config()->has("currencies.$code")) {
            return $code;
        }

        return self::deduceCurrency();
    }

    public function currencySymbol(): string
    {
        return config()->string('currencies.'.$this->currency().'.symbol', $this->currency());
    }

    public function currencyDecimals(): int
    {
        return config()->integer('currencies.'.$this->currency().'.decimals', 2);
    }

    public function formatMoney(int|float|string|null $amount): string
    {
        if (! is_numeric($amount)) {
            return '';
        }

        return $this->currencySymbol().Number::format((float) $amount, precision: $this->currencyDecimals());
    }

    public function googleAnalyticsId(): string
    {
        $id = config('site.google_analytics_id');

        return is_string($id) ? $id : '';
    }

    public function googleMapsApiKey(): string
    {
        $key = config('site.google_maps_api_key');

        return is_string($key) ? $key : '';
    }

    public function brandName(): string
    {
        $title = $this->title();

        return $title !== '' ? $title : config()->string('app.name');
    }

    /**
     * @return array{url: string, height: int}|null
     */
    public function mailLogo(): ?array
    {
        $source = config('site.logo_header.source');

        if (! is_string($source)) {
            return ['url' => asset('images/wire-up-mail-logo.png'), 'height' => 32];
        }

        if (str_ends_with(mb_strtolower($source), '.svg')) {
            return null;
        }

        $url = $this->logoUrl('logo_header', maxHeight: 100);

        return $url === null ? null : ['url' => $url, 'height' => 50];
    }

    /**
     * @return array{light: ?string, dark: ?string}
     */
    public function logosForSurface(string $role, string $slot): array
    {
        $onLight = $this->logoUrl($role);
        $onDark = $this->logoUrl($role.'_dark');

        $lightPalette = $this->themeColors();
        $darkPalette = $this->darkThemeColors() ?: $lightPalette;

        return [
            'light' => $this->isDarkSurface($lightPalette, $slot) ? ($onDark ?: $onLight) : $onLight,
            'dark' => $this->isDarkSurface($darkPalette, $slot) ? ($onDark ?: $onLight) : ($onLight ?: $onDark),
        ];
    }

    public function logoUrl(string $role, string $crop = 'default', int $maxHeight = 320): ?string
    {
        $item = config('site.'.$role);

        if (! is_array($item)) {
            return null;
        }

        $source = $item['source'] ?? null;

        if (! is_string($source) || $source === '') {
            return null;
        }

        $crops = is_array($item['crop'] ?? null) ? $item['crop'] : [];
        $variant = is_array($crops[$crop] ?? null) ? $crops[$crop] : null;

        $options = ["h={$maxHeight}", 'q=80', 'fm=png'];

        if ($variant !== null && ($variant['crop_w'] ?? 0) > 0 && ($variant['crop_h'] ?? 0) > 0) {
            $options[] = sprintf(
                'crop=%d-%d-%d-%d',
                $variant['crop_w'],
                $variant['crop_h'],
                $variant['crop_x'] ?? 0,
                $variant['crop_y'] ?? 0,
            );
        }

        return ImageService::url(implode(',', $options), $source);
    }

    public function faviconUrl(string $crop = 'default'): ?string
    {
        $item = config('site.favicon');

        return $this->imageUrl(is_array($item) ? $item : null, $crop, ['fm' => 'png']);
    }

    public function defaultOgImageUrl(string $crop = 'default'): ?string
    {
        $item = config('site.default_og_image');

        return $this->imageUrl(is_array($item) ? $item : null, $crop, ['w' => 1200, 'h' => 630, 'fm' => 'jpg']);
    }

    public function color(string $slot): ?string
    {
        $palette = $this->themeColors() ?: config()->array('theme.presets.default.colors');

        $value = $palette[$slot] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    public function themeColors(): array
    {
        return $this->paletteFromTheme((string) config('site.theme', ''), 'site.colors');
    }

    /**
     * @return array<string, string>
     */
    public function darkThemeColors(): array
    {
        $mode = (string) config('site.theme_dark', '');
        $mode = $mode === '' ? config()->string('theme.default_dark') : $mode;

        if ($mode === 'none') {
            return [];
        }

        $theme = (string) config('site.theme', '');

        if ($theme === 'custom' || $mode === 'custom') {
            $custom = $this->normalizePalette(config('site.colors_dark', []));

            if ($custom !== []) {
                return $custom;
            }

            $theme = config()->string('theme.default');
        }

        $theme = $theme === '' ? config()->string('theme.default') : $theme;

        return $this->normalizePalette(config()->array("theme.presets.$theme.colors_dark", []));
    }

    public function themeToggleEnabled(): bool
    {
        return $this->darkThemeColors() !== [] && (bool) config('site.header_theme_toggle', false);
    }

    public function themeCss(): string
    {
        return new ThemeCss($this)->css();
    }

    public function googleFontsUrl(): ?string
    {
        return new ThemeCss($this)->googleFontsUrl();
    }

    public function customCss(): string
    {
        return mb_trim((string) config('site.custom_css', ''));
    }

    public function headScripts(): string
    {
        return mb_trim((string) config('site.head_scripts', ''));
    }

    public function bodyScripts(): string
    {
        return mb_trim((string) config('site.body_scripts', ''));
    }

    /**
     * @return array<string, string>
     */
    public function socialLinks(): array
    {
        $social = config('site.social');
        if (! is_array($social)) {
            return [];
        }

        $links = [];
        foreach (array_keys(config()->array('social.platforms')) as $platform) {
            $key = (string) $platform;
            $url = $social[$key] ?? null;
            if (is_string($url) && $url !== '') {
                $links[$key] = $url;
            }
        }

        return $links;
    }

    public function headerHeight(): ?int
    {
        return $this->pixelSetting('header_height', 'theme.header_height_min', 'theme.header_height_max');
    }

    public function headerLogoHeight(): ?int
    {
        return $this->pixelSetting('header_logo_height', 'theme.header_logo_height_min', 'theme.header_logo_height_max');
    }

    public function blockSpaceTop(): bool
    {
        return (bool) config('site.block_space_top', config()->boolean('theme.default_block_space_top'));
    }

    public function blockSpacing(): string
    {
        $value = (string) config('site.block_spacing', '');

        return array_key_exists($value, config()->array('theme.block_spacings'))
            ? $value
            : config()->string('theme.default_block_spacing');
    }

    public function socialIconVariant(): string
    {
        $variant = (string) config('site.social_icon_variant', '');

        return array_key_exists($variant, config()->array('social.icon_variants'))
            ? $variant
            : config()->string('social.default_icon_variant', 'solid');
    }

    /**
     * @return array{stack: string, google: string}
     */
    public function fontFor(string $slot): array
    {
        $key = (string) config("site.{$slot}_font", '') ?: config()->string('theme.default_font');

        if ($key === 'custom') {
            $custom = mb_trim((string) config("site.{$slot}_font_custom", ''));

            return $custom === ''
                ? ['stack' => '', 'google' => '']
                : ['stack' => '"'.$custom.'", sans-serif', 'google' => $custom];
        }

        return [
            'stack' => config()->string("theme.fonts.$key.stack", ''),
            'google' => config()->string("theme.fonts.$key.google", ''),
        ];
    }

    public function pixelSetting(string $key, string $minKey, string $maxKey): ?int
    {
        $value = config("site.{$key}");

        if (! is_numeric($value)) {
            return null;
        }

        $pixels = (int) $value;

        return $pixels >= config()->integer($minKey) && $pixels <= config()->integer($maxKey) ? $pixels : null;
    }

    /**
     * @return array{key: string, name: string, builtin: bool, display: array{background: bool, position: string, sticky: bool, mobile: string}, items: array<string, array<int, mixed>>}
     */
    private static function emptyBuiltinMenu(string $key): array
    {
        return [
            'key' => $key,
            'name' => ucfirst($key),
            'builtin' => true,
            'display' => self::normalizeMenuDisplay(null),
            'items' => [],
        ];
    }

    /**
     * @param  array<string, string>  $palette
     */
    private function isDarkSurface(array $palette, string $slot): bool
    {
        $hex = $palette[$slot] ?? null;

        if (! is_string($hex) || preg_match('/^#[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return false;
        }

        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        return (0.2126 * (float) $r + 0.7152 * (float) $g + 0.0722 * (float) $b) / 255 < 0.5;
    }

    private function resolveHomePage(bool $publishedOnly): ?Page
    {
        $configured = Settings::get('home_page_id');

        if (is_numeric($configured)) {
            $page = Page::query()
                ->when($publishedOnly, fn (Builder $query): Builder => $query->published())
                ->with(['translations', 'slugs'])
                ->whereKey((int) $configured)
                ->first();

            if ($page instanceof Page) {
                return $page;
            }
        }

        return Page::query()
            ->when($publishedOnly, fn (Builder $query): Builder => $query->published())
            ->with(['translations', 'slugs'])
            ->whereHas('slugs', function (Builder $query): void {
                $query->where('slug', 'home');
            })
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array<string, string>
     */
    private function paletteFromTheme(string $theme, string $customConfigKey): array
    {
        if ($theme === '' || $theme === 'none') {
            return [];
        }

        $colors = $theme === 'custom'
            ? config($customConfigKey, [])
            : config()->array("theme.presets.$theme.colors", []);

        return $this->normalizePalette($colors);
    }

    /**
     * @return array<string, string>
     */
    private function normalizePalette(mixed $colors): array
    {
        if (! is_array($colors)) {
            return [];
        }

        $palette = [];
        foreach ($colors as $slot => $hex) {
            if (is_string($slot) && is_string($hex)) {
                $palette[$slot] = $hex;
            }
        }

        return $palette;
    }

    private function localeValue(mixed $value): string
    {
        if (! is_array($value)) {
            return '';
        }

        $candidates = [app()->getLocale(), config()->string('app.fallback_locale', 'en')];
        foreach ($candidates as $locale) {
            if (is_string($value[$locale] ?? null) && $value[$locale] !== '') {
                return $value[$locale];
            }
        }

        foreach ($value as $text) {
            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>|null  $item
     * @param  array<string, mixed>  $params
     */
    private function imageUrl(?array $item, string $crop, array $params): ?string
    {
        $source = $item['source'] ?? null;
        if (! is_string($source) || $source === '') {
            return null;
        }

        $crops = is_array($item['crop'] ?? null) ? $item['crop'] : [];
        $variant = is_array($crops[$crop] ?? null) ? $crops[$crop] : null;

        return ImageService::url(
            $variant !== null ? $this->cropString([...$variant, ...$params]) : $this->scaleString($params),
            $source,
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function scaleString(array $params): string
    {
        $values = [
            'w' => 1200,
            'h' => 800,
            'q' => 80,
            'fm' => 'jpg',
            ...Arr::only($params, ['w', 'h', 'q', 'fm']),
        ];

        return implode(',', [
            "w={$values['w']}",
            "h={$values['h']}",
            "q={$values['q']}",
            "fm={$values['fm']}",
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function cropString(array $params): string
    {
        $default = [
            'crop_w' => 1200,
            'crop_h' => 800,
            'crop_x' => 0,
            'crop_y' => 0,
            'w' => 1200,
            'h' => 800,
            'q' => 80,
            'fm' => 'jpg',
        ];

        $values = [...$default, ...Arr::only($params, array_keys($default))];

        $crop = sprintf(
            '%d-%d-%d-%d',
            $values['crop_w'],
            $values['crop_h'],
            $values['crop_x'],
            $values['crop_y']
        );

        return implode(',', [
            "w={$values['w']}",
            "h={$values['h']}",
            "crop={$crop}",
            "q={$values['q']}",
            "fm={$values['fm']}",
        ]);
    }
}
