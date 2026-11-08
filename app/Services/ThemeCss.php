<?php

declare(strict_types=1);

namespace App\Services;

final readonly class ThemeCss
{
    public function __construct(private SettingsService $settings) {}

    public function css(): string
    {
        $source = $this->settings->themeColors() ?: config()->array('theme.presets.default.colors');
        $palette = [];
        foreach ($source as $slot => $hex) {
            if (is_string($slot) && is_string($hex)) {
                $palette[$slot] = $hex;
            }
        }

        $root = $this->colorDeclarations($palette);

        $dark = [];
        $accent = $this->accentDeclarations($palette);
        if ($accent !== []) {
            $root = [...$root, ...$accent];
            $dark = $accent;
        }

        $radiusKey = (string) config('site.radius', '') ?: config()->string('theme.default_radius');
        $radius = config()->string("theme.radii.$radiusKey", '');
        if ($radius !== '') {
            $root[] = "--wire-radius:$radius";

            foreach (['sm', 'md', 'lg', 'xl'] as $size) {
                $root[] = "--radius-$size:$radius";
            }
        }

        $buttonRadiusKey = (string) config('site.button_radius', '') ?: config()->string('theme.default_button_radius');
        $buttonRadius = config()->string("theme.button_radii.$buttonRadiusKey", '');
        $root[] = '--wire-btn-radius:'.($buttonRadius !== '' ? $buttonRadius : 'var(--wire-radius)');

        $borderWidthKey = (string) config('site.border_width', '') ?: config()->string('theme.default_border_width');
        $borderWidth = config()->string("theme.border_widths.$borderWidthKey", '');
        if ($borderWidth !== '') {
            $root[] = "--wire-border-width:$borderWidth";
        }

        $headingStack = $this->settings->fontFor('heading')['stack'];
        $bodyStack = $this->settings->fontFor('body')['stack'];
        if ($headingStack !== '') {
            $root[] = "--wire-heading-font:$headingStack";
        }
        if ($bodyStack !== '') {
            $root[] = "--wire-body-font:$bodyStack";
            $root[] = "--font-sans:$bodyStack";
        }

        $containerKey = (string) config('site.container', '') ?: config()->string('theme.default_container');
        $container = config()->string("theme.containers.$containerKey", '');

        if ($containerKey === 'custom') {
            $width = $this->settings->pixelSetting('container_width', 'theme.container_width_min', 'theme.container_width_max')
                ?? config()->integer('theme.default_container_width');
            $container = "{$width}px";
        }

        if ($container !== '') {
            $root[] = "--wire-container:$container";
        }

        $gutter = $this->settings->pixelSetting('gutter', 'theme.gutter_min', 'theme.gutter_max');
        $root[] = '--wire-gutter:'.($gutter === null ? '1.5rem' : "{$gutter}px");
        $fullGutter = $containerKey === 'full'
            ? match ($this->settings->blockSpacing()) {
                'small' => '3rem',
                'large' => '5rem',
                default => '4rem',
            }
        : '';

        $headingSizeKey = (string) config('site.heading_size', '') ?: config()->string('theme.default_heading_size');
        $bodySizeKey = (string) config('site.body_size', '') ?: config()->string('theme.default_body_size');
        $headingSize = config()->string("theme.heading_sizes.$headingSizeKey", '');
        $bodySize = config()->string("theme.body_sizes.$bodySizeKey", '');
        if ($headingSize !== '') {
            $root[] = "--wire-heading-size:$headingSize";
        }
        if ($bodySize !== '') {
            $root[] = "--wire-body-size:$bodySize";
        }

        $leadingKey = (string) config('site.body_leading', '') ?: config()->string('theme.default_body_leading');
        $leading = config()->string("theme.body_leadings.$leadingKey", '');
        if ($leading !== '') {
            $root[] = "--wire-body-leading:$leading";
        }

        $indentKey = (string) config('site.list_indent', '') ?: config()->string('theme.default_list_indent');
        $indent = config()->string("theme.list_indents.$indentKey", '');
        if ($indent !== '') {
            $root[] = "--wire-list-indent:$indent";
        }

        $headerHeight = $this->settings->headerHeight();
        if ($headerHeight !== null) {
            $root[] = "--wire-header-height:{$headerHeight}px";
        }

        $logoHeight = $this->settings->headerLogoHeight();
        if ($logoHeight !== null) {
            $root[] = "--wire-logo-height:{$logoHeight}px";
        }

        $css = ':root{'.implode(';', $root).'}'.($dark === [] ? '' : '.dark{'.implode(';', $dark).'}');

        $darkPalette = $this->settings->darkThemeColors();
        if ($darkPalette !== []) {
            $darkVars = implode(';', [...$this->colorDeclarations($darkPalette), ...$this->accentDeclarations($darkPalette)]);
            $css .= ':root.dark{'.$darkVars.'}';
            $css .= '@media(prefers-color-scheme:dark){:root:where(:not(.light)){'.$darkVars.'}}';
        }

        if ($fullGutter !== '') {
            $css .= '@media(min-width:768px){:root{--wire-gutter:'.$fullGutter.'}}';
        }

        return $css;
    }

    public function googleFontsUrl(): ?string
    {
        $families = [];
        foreach (['heading', 'body'] as $slot) {
            $key = (string) config("site.{$slot}_font", '');

            if ($key === '') {
                continue;
            }

            $family = $key === 'custom'
                ? mb_trim((string) config("site.{$slot}_font_custom", ''))
                : config()->string("theme.fonts.$key.google", '');

            if ($family !== '') {
                $families[$family] = $family;
            }
        }

        if ($families === []) {
            return null;
        }

        $params = implode('&', array_map(
            fn (string $family): string => 'family='.str_replace(' ', '+', $family).':wght@400;500;600;700',
            $families,
        ));

        return "https://fonts.googleapis.com/css2?$params&display=swap";
    }

    /**
     * @param  array<string, string>  $palette
     * @return list<string>
     */
    private function colorDeclarations(array $palette): array
    {
        $declarations = [];
        foreach ($palette as $slot => $hex) {
            if ($hex === '') {
                continue;
            }

            $name = match ($slot) {
                'background' => 'body-bg',
                'text' => 'body-text',
                default => str_replace('_', '-', $slot),
            };
            $declarations[] = "--wire-$name:$hex";
        }

        if (($palette['heading'] ?? '') === '') {
            $declarations[] = '--wire-heading:var(--wire-body-text)';
        }

        return $declarations;
    }

    /**
     * @param  array<string, string>  $palette
     * @return list<string>
     */
    private function accentDeclarations(array $palette): array
    {
        $accentColor = $palette['accent'] ?? $palette['primary_bg'] ?? null;

        if ($accentColor === null || ! isset($palette['primary_text'])) {
            return [];
        }

        return [
            "--color-accent:{$accentColor}",
            "--color-accent-content:{$accentColor}",
            "--color-accent-foreground:{$palette['primary_text']}",
        ];
    }
}
