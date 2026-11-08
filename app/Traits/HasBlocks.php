<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Block;
use App\Services\BlockHtmlSanitizer;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasBlocks
{
    /**
     * @return MorphMany<Block, $this>
     */
    public function blocks(): MorphMany
    {
        return $this->morphMany(Block::class, 'blockable')->orderBy('position');
    }

    /**
     * @return array<int|string, array{id: string, type: string, position: int, content: array<string, mixed>}>
     */
    public function getBlocksArray(): array
    {
        return $this->blocks
            ->mapWithKeys(fn (Block $block): array => [
                (string) $block->id => [
                    'id' => (string) $block->id,
                    'type' => $block->type->value,
                    'position' => $block->position,
                    'content' => $block->content ?? [],
                ],
            ])
            ->all();
    }

    /**
     * @param  array<int|string, array{id?: string, type: string, position?: int, content?: array<string, mixed>}>  $blocks
     */
    public function updateBlocks(array $blocks): void
    {
        $keptIds = collect($blocks)
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $this->blocks()->whereNotIn('id', $keptIds)->delete();

        foreach (array_values($blocks) as $position => $block) {
            $attributes = [
                'type' => $block['type'],
                'position' => $position,
                'content' => BlockHtmlSanitizer::forBlock($block['type'], $block['content'] ?? []),
            ];

            $existing = is_numeric($block['id'] ?? null) ? $this->blocks()->whereKey((int) $block['id'])->first() : null;

            $existing === null ? $this->blocks()->create($attributes) : $existing->update($attributes);
        }
    }

    protected static function bootHasBlocks(): void
    {
        static::deleting(function (self $model): void {
            $model->blocks()->delete();
        });
    }
}
