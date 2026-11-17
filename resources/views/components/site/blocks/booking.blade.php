@props(['block'])

@php
    $content = is_array($block->content) ? $block->content : [];
    $hasBg = (bool) ($content['hasBackground'] ?? false);
    $key = $block->id ?? md5((string) json_encode($content));
@endphp

<section @class([
    'w-full',
    'bg-(--wire-card-bg) text-(--wire-card-text)' => $hasBg,
    ($pad ?? 'py-16') => $hasBg,
])>
    <div class="mx-auto max-w-(--wire-container) px-(--wire-gutter)">
        <livewire:site.booking :config="$content" :block-id="$block->id" wire:key="booking-{{ $key }}" />
    </div>
</section>
