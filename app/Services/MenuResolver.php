<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Page;
use Illuminate\Database\Eloquent\Collection;

final readonly class MenuResolver
{
    public function __construct(private SettingsService $settings) {}

    /**
     * @return array{display: array{background: bool, position: string, sticky: bool, mobile: string}, items: array<int, array{type: string, label: string, url: string, target: string, appearance: string, icon: ?string, icon_svg: string, badge: string, badgeColor: string}>}|null
     */
    public function menuForDisplay(string $key): ?array
    {
        $menu = collect($this->settings->allMenus())->firstWhere('key', $key);

        if ($menu === null) {
            return null;
        }

        $items = $this->menu($key);

        if ($items === []) {
            return null;
        }

        return [
            'display' => $menu['display'],
            'items' => $items,
        ];
    }

    /**
     * @return array<int, array{type: string, label: string, url: string, target: string, appearance: string, icon: ?string, icon_svg: string, badge: string, badgeColor: string}>
     */
    public function menu(string $key): array
    {
        $menu = collect($this->settings->allMenus())->firstWhere('key', $key);

        if ($menu === null) {
            return [];
        }

        $items = $this->localeMenuItems($menu['items']);
        $pages = $this->menuPages($items);

        $resolved = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (($item['type'] ?? null) === 'account') {
                array_push($resolved, ...$this->resolveAccountItems($item));

                continue;
            }

            $entry = $this->resolveMenuItem($item, $pages);
            if ($entry !== null) {
                $resolved[] = $entry;
            }
        }

        return $resolved;
    }

    /**
     * @param  array<array-key, mixed>  $menus
     * @return array<int, mixed>
     */
    private function localeMenuItems(array $menus): array
    {
        $locale = app()->getLocale();
        $items = $menus[$locale] ?? null;

        if (is_array($items) && $items !== []) {
            return array_values($items);
        }

        $default = config()->string('app.fallback_locale', 'en');
        if ($locale !== $default && is_array($menus[$default] ?? null)) {
            return array_values($menus[$default]);
        }

        return [];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return Collection<int, Page>
     */
    private function menuPages(array $items): Collection
    {
        $ids = [];
        foreach ($items as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'page' && isset($item['page_id'])) {
                $ids[] = (int) $item['page_id'];
            }
        }

        if ($ids === []) {
            return new Collection;
        }

        return Page::query()
            ->published()
            ->whereIn('id', $ids)
            ->with('slugs')
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<int, Page>  $pages
     * @return array{type: string, label: string, url: string, target: string, appearance: string, icon: ?string, icon_svg: string, badge: string, badgeColor: string}|null
     */
    private function resolveMenuItem(array $item, Collection $pages): ?array
    {
        $label = is_string($item['label'] ?? null) ? $item['label'] : '';
        if ($label === '') {
            return null;
        }

        $type = $item['type'] ?? null;

        if ($type === 'heading') {
            return [
                'type' => 'heading',
                'label' => $label,
                'url' => '',
                'target' => '_self',
                'appearance' => 'link',
                'icon' => null,
                'icon_svg' => '',
                'badge' => '',
                'badgeColor' => 'zinc',
            ];
        }

        $url = null;

        if ($type === 'link') {
            $url = is_string($item['url'] ?? null) && $item['url'] !== '' ? $item['url'] : null;
        } elseif ($type === 'page') {
            $raw = is_scalar($item['page_id'] ?? null) ? (string) $item['page_id'] : '';

            if (str_starts_with($raw, SettingsService::AUTH_LINK_PREFIX)) {
                $url = $this->settings->resolveAuthLink($raw);
            } else {
                $page = $raw !== '' ? $pages->get((int) $raw) : null;
                if ($page instanceof Page && $page->slug !== '') {
                    $url = route('page', $page->slug);
                }
            }
        }

        if ($url === null) {
            return null;
        }

        $icon = is_string($item['icon'] ?? null) ? $item['icon'] : '';
        $badgeColor = is_string($item['badgeColor'] ?? null) ? $item['badgeColor'] : 'zinc';
        $iconSvg = is_string($item['icon_svg'] ?? null) ? $item['icon_svg'] : '';
        $appearance = in_array($item['appearance'] ?? null, ['button', 'icon'], true) ? (string) $item['appearance'] : 'link';

        if ($appearance === 'icon' && $iconSvg === '') {
            $appearance = 'link';
        }

        return [
            'type' => 'link',
            'label' => $label,
            'url' => $url,
            'target' => ($item['target'] ?? null) === '_blank' ? '_blank' : '_self',
            'appearance' => $appearance,
            'icon' => in_array($icon, config()->array('menu.icons'), true) ? $icon : null,
            'icon_svg' => $appearance === 'icon' ? $iconSvg : '',
            'badge' => is_string($item['badge'] ?? null) ? $item['badge'] : '',
            'badgeColor' => in_array($badgeColor, config()->array('menu.badge_colors'), true) ? $badgeColor : 'zinc',
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<int, array{type: string, label: string, url: string, target: string, appearance: string, icon: ?string, icon_svg: string, badge: string, badgeColor: string}>
     */
    private function resolveAccountItems(array $item): array
    {
        $appearance = ($item['appearance'] ?? null) === 'button' ? 'button' : 'link';

        $entry = fn (string $type, string $label, string $url): array => [
            'type' => $type,
            'label' => $label,
            'url' => $url,
            'target' => '_self',
            'appearance' => $appearance,
            'icon' => null,
            'icon_svg' => '',
            'badge' => '',
            'badgeColor' => 'zinc',
        ];

        if (auth()->check()) {
            return [
                $entry('link', __('Account'), route('account')),
                $entry('logout', __('Log out'), route('logout')),
            ];
        }

        $links = [$entry('link', __('Log in'), route('login'))];

        if ($this->settings->allowsRegistration()) {
            $links[] = $entry('link', __('Sign up'), route('register'));
        }

        return $links;
    }
}
