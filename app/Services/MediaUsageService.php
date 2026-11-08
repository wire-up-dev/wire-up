<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Block;
use App\Models\Media;
use App\Models\Mediable;
use App\Models\Page;
use App\Models\Record;
use App\Models\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class MediaUsageService
{
    private const array FILE_NAME_COLUMNS = [
        'blocks' => 'content',
        'settings' => 'value',
        'pages' => 'metadata',
        'records' => 'metadata',
    ];

    public function isInUse(Media $media): bool
    {
        if ($media->mediables()->exists()) {
            return true;
        }
        if ($this->blockQuery($media)->exists()) {
            return true;
        }

        if ($this->layoutOwners($media) !== []) {
            return true;
        }

        return $this->settingsQuery($media)->exists();
    }

    /**
     * @return array<int, int>
     */
    public function unusedIds(): array
    {
        $candidates = Media::query()->whereDoesntHave('mediables')->pluck('source', 'id');

        if ($candidates->isEmpty()) {
            return [];
        }

        $referenced = $this->referencedFileNames();

        return $candidates
            ->reject(fn (string $source): bool => isset($referenced[basename($source)]))
            ->keys()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function labels(Media $media): array
    {
        $pivot = $media->mediables()
            ->with('mediable')
            ->get()
            ->map(fn (Mediable $mediable): Model => $mediable->mediable)
            ->map(fn (Model $owner): string => $this->ownerLabel($owner))
            ->all();

        $blocks = $this->blockQuery($media)
            ->with('blockable')
            ->get()
            ->map(fn (Block $block): string => $this->blockLabel($block))
            ->all();

        $layouts = array_map(
            fn (Model $owner): string => $this->ownerLabel($owner).' — '.__('Background'),
            $this->layoutOwners($media),
        );

        $settings = $this->settingsQuery($media)->exists()
            ? [__('Site settings')]
            : [];

        return collect([...$pivot, ...$blocks, ...$layouts, ...$settings])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Builder<Block>
     */
    private function blockQuery(Media $media): Builder
    {
        return $this->mentioning(Block::query(), 'content', $media);
    }

    /**
     * @return Builder<Settings>
     */
    private function settingsQuery(Media $media): Builder
    {
        return $this->mentioning(Settings::query(), 'value', $media);
    }

    /**
     * @return array<int, Page|Record>
     */
    private function layoutOwners(Media $media): array
    {
        return [
            ...$this->mentioning(Page::query(), 'metadata', $media)->get()->all(),
            ...$this->mentioning(Record::query(), 'metadata', $media)->get()->all(),
        ];
    }

    /**
     * @return array<string, true>
     */
    private function referencedFileNames(): array
    {
        $names = [];

        foreach (self::FILE_NAME_COLUMNS as $table => $column) {
            foreach (DB::table($table)->select(['id', $column])->whereNotNull($column)->lazyById(500) as $row) {
                preg_match_all('/[A-Za-z0-9._~%+-]+\.[A-Za-z0-9]{2,5}/', (string) $row->{$column}, $matches);

                foreach ($matches[0] as $name) {
                    $names[$name] = true;
                }
            }
        }

        return $names;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function mentioning(Builder $query, string $column, Media $media): Builder
    {
        $needle = '%'.$this->needle($media).'%';

        $asText = $query->getQuery()->getGrammar()->wrap($column).'::text like ?';

        return $query->getModel()->getConnection()->getDriverName() === 'pgsql' ? $query->whereRaw($asText, [$needle]) : $query->where($column, 'like', $needle);
    }

    private function needle(Media $media): string
    {
        return basename($media->source);
    }

    private function ownerLabel(Model $owner): string
    {
        $type = class_basename($owner);
        $label = $owner->getAttribute('title') ?? $owner->getAttribute('name');

        return is_string($label) && $label !== ''
            ? $type.': '.$label
            : $type.' #'.$owner->getKey();
    }

    private function blockLabel(Block $block): string
    {
        return $this->ownerLabel($block->blockable).' — '.$block->type->label();
    }
}
