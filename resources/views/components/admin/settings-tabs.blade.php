@php
    $section = resolve(\App\Services\SettingsNavigation::class)->currentSection();
@endphp

@if ($section !== null && count($section['tabs']) > 1)
    <flux:tabs
        variant="pills"
        scrollable
        class="mb-8"
        wire:key="settings-tabs-{{ md5(implode('|', array_column($section['tabs'], 'route'))) }}"
    >
        @foreach ($section['tabs'] as $tab)
            <flux:tab :href="route($tab['route'])" :selected="$tab['current']" wire:navigate>
                {{ $tab['label'] }}
                @if ($tab['badge'] !== null)
                    <flux:badge
                        size="sm"
                        @class([
                            'ms-1.5',
                            'bg-white/20! text-white! dark:bg-zinc-800/10! dark:text-zinc-800!' => $tab['current'],
                        ])
                    >
                        {{ $tab['badge'] }}
                    </flux:badge>
                @endif
            </flux:tab>
        @endforeach
    </flux:tabs>
@endif
