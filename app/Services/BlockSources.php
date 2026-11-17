<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Block;
use App\Models\Page;
use App\Models\Record;
use App\Models\RecordType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final readonly class BlockSources
{
    private const int PER_GROUP = 6;

    /**
     * @return list<array{heading: string, items: list<array{type: string, id: int, title: string, blocks: int}>}>
     */
    public function search(string $term, string $excludeType = '', ?int $excludeId = null): array
    {
        $term = mb_trim($term);
        $groups = [];

        if (Gate::allows('pages.view')) {
            $pages = Page::query()
                ->with('translations')
                ->withCount('blocks')
                ->has('blocks')
                ->when($term !== '', fn (Builder $query): Builder => $query->whereTranslationLike('title', $term))
                ->when($excludeType === 'page' && $excludeId !== null, fn (Builder $query): Builder => $query->whereKeyNot($excludeId))
                ->latest('updated_at')
                ->limit(self::PER_GROUP)
                ->get();

            $groups[] = ['heading' => __('Pages'), 'items' => $pages->map(fn (Page $page): array => $this->item('page', $page))->values()->all()];
        }

        foreach (RecordType::ordered() as $type) {
            if ($type->admin_only || Gate::denies("records.{$type->key}.view")) {
                continue;
            }

            $records = Record::query()
                ->with('translations')
                ->withCount('blocks')
                ->has('blocks')
                ->where('record_type_id', $type->id)
                ->when($term !== '', fn (Builder $query): Builder => $query->matchingSearch($term, $type))
                ->when($excludeType === 'record' && $excludeId !== null, fn (Builder $query): Builder => $query->whereKeyNot($excludeId))
                ->latest('updated_at')
                ->limit(self::PER_GROUP)
                ->get();

            $groups[] = ['heading' => $type->name, 'items' => $records->map(fn (Record $record): array => $this->item('record', $record))->values()->all()];
        }

        return array_values(array_filter($groups, fn (array $group): bool => $group['items'] !== []));
    }

    /**
     * @return array{title: string, blocks: list<array{id: int, label: string, icon: string, summary: string}>}|null
     */
    public function blocksOf(string $type, int $id): ?array
    {
        $owner = match ($type) {
            'page' => Page::query()->find($id),
            'record' => Record::query()->find($id),
            default => null,
        };

        if (! $this->viewable($owner)) {
            return null;
        }

        /** @var Page|Record $owner */
        return [
            'title' => $owner->title ?: __('Untitled'),
            'blocks' => $owner->blocks->map(fn (Block $block): array => [
                'id' => $block->id,
                'label' => $block->type->label(),
                'icon' => $block->type->icon(),
                'summary' => $block->plainTextField('heading') ?: Str::limit($block->plainText(), 90),
            ])->values()->all(),
        ];
    }

    public function copyable(int $blockId): ?Block
    {
        $block = Block::query()->with('blockable')->find($blockId);

        return $block instanceof Block && $this->viewable($block->blockable) ? $block : null;
    }

    private function viewable(?Model $owner): bool
    {
        return match (true) {
            $owner instanceof Page => Gate::allows('pages.view'),
            $owner instanceof Record => ! $owner->recordType->admin_only && Gate::allows("records.{$owner->recordType->key}.view"),
            default => false,
        };
    }

    /**
     * @return array{type: string, id: int, title: string, blocks: int}
     */
    private function item(string $type, Page|Record $owner): array
    {
        return [
            'type' => $type,
            'id' => $owner->id,
            'title' => $owner->title ?: __('Untitled'),
            'blocks' => (int) $owner->getAttribute('blocks_count'),
        ];
    }
}
