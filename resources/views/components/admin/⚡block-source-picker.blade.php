<?php

declare(strict_types=1);

use App\Services\BlockSources;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

return new class extends Component
{
    #[Locked]
    public string $excludeType = '';

    #[Locked]
    public ?int $excludeId = null;

    public string $search = '';

    public string $sourceType = '';

    public ?int $sourceId = null;

    /**
     * @return list<array{heading: string, items: list<array{type: string, id: int, title: string, blocks: int}>}>
     */
    #[Computed]
    public function groups(): array
    {
        return resolve(BlockSources::class)->search($this->search, $this->excludeType, $this->excludeId);
    }

    /**
     * @return array{title: string, blocks: list<array{id: int, label: string, icon: string, summary: string}>}|null
     */
    #[Computed]
    public function source(): ?array
    {
        return $this->sourceId === null ? null : resolve(BlockSources::class)->blocksOf($this->sourceType, $this->sourceId);
    }

    public function open(string $type, int $id): void
    {
        $this->sourceType = $type;
        $this->sourceId = $id;
    }

    #[On('block-picker-opened')]
    public function back(): void
    {
        $this->sourceType = '';
        $this->sourceId = null;
    }

    public function copy(int $blockId): void
    {
        $this->dispatch('block-source-selected', blockId: $blockId);
    }
};
?>

<div class="space-y-4" data-block-source-picker>
    @if ($this->source)
        <div class="flex items-center gap-3">
            <flux:button size="sm" variant="ghost" icon="arrow-left" wire:click="back" :aria-label="__('Back')" />
            <flux:heading>{{ $this->source['title'] }}</flux:heading>
        </div>

        <div class="space-y-2">
            @forelse ($this->source['blocks'] as $sourceBlock)
                <div
                    wire:key="source-block-{{ $sourceBlock['id'] }}"
                    class="flex items-center gap-4 rounded-lg border border-zinc-200 p-3 dark:border-white/10"
                >
                    <flux:icon name="{{ $sourceBlock['icon'] }}" class="size-5 shrink-0 text-zinc-400" />
                    <div class="min-w-0 flex-1">
                        <flux:heading size="sm">{{ $sourceBlock['label'] }}</flux:heading>
                        @if ($sourceBlock['summary'] !== '')
                            <flux:text size="sm" class="truncate">{{ $sourceBlock['summary'] }}</flux:text>
                        @endif
                    </div>
                    <flux:button size="sm" icon="document-duplicate" wire:click="copy({{ $sourceBlock['id'] }})">
                        {{ __('Copy here') }}
                    </flux:button>
                </div>
            @empty
                <flux:text>{{ __('This page has no blocks.') }}</flux:text>
            @endforelse
        </div>
    @else
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            clearable
            placeholder="{{ __('Search pages and records…') }}"
        />

        <div class="space-y-6">
            @forelse ($this->groups as $group)
                <div wire:key="source-group-{{ md5($group['heading']) }}" class="space-y-2">
                    <flux:heading
                        size="sm"
                        class="text-zinc-500 dark:text-zinc-400"
                    >{{ $group['heading'] }}</flux:heading>
                    @foreach ($group['items'] as $item)
                        <button
                            type="button"
                            wire:key="source-{{ $item['type'] }}-{{ $item['id'] }}"
                            wire:click="open('{{ $item['type'] }}', {{ $item['id'] }})"
                            class="flex w-full items-center justify-between gap-4 rounded-lg border border-zinc-200 p-3 text-left transition hover:border-zinc-300 hover:bg-zinc-50 dark:border-white/10 dark:hover:border-white/20 dark:hover:bg-white/5"
                        >
                            <flux:heading size="sm" class="truncate">{{ $item['title'] }}</flux:heading>
                            <flux:text size="sm" class="shrink-0">
                                {{ trans_choice(':count block|:count blocks', $item['blocks']) }}
                            </flux:text>
                        </button>
                    @endforeach
                </div>
            @empty
                <flux:text class="py-10 text-center text-zinc-500 dark:text-zinc-400">
                    {{ __('No pages or records match your search.') }}
                </flux:text>
            @endforelse
        </div>
    @endif
</div>
