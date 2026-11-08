<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Record;
use App\Services\ActivityLog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class UpdateRecordAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Record $record, array $attributes): void
    {
        DB::transaction(function () use ($record, $attributes): void {
            $record->recordBaselineRevision();
            $previousStatus = $record->computed_status;

            $redirects = new RedirectMovedContentAction();
            $previousPaths = isset($attributes['slugs']) ? $redirects->livePaths($record) : [];

            $record->update([
                ...Arr::except($attributes, ['slugs', 'status', 'blocks', 'media', 'categories']),
                ...$record->publicationAttributes($attributes),
                'updated_at' => now(),
            ]);

            if (isset($attributes['slugs'])) {
                $record->updateSlugs($attributes['slugs']);
                $redirects->handle($record, $previousPaths);
            }

            if (isset($attributes['blocks'])) {
                $record->updateBlocks($attributes['blocks']);
            }

            if (isset($attributes['categories'])) {
                $record->categories()->sync($attributes['categories']);
            }

            if (is_array($attributes['media'] ?? null)) {
                $this->syncMedia($record, $attributes['media']);
            }

            ActivityLog::contentSaved($record, $previousStatus, $record->recordRevision());
        });

        if ($record->recordType->admin_only) {
            new MakeDocumentsPrivateAction()->handle($record);
        }
    }

    /**
     * @param  array<string, array<string, array<int, array<string, mixed>>>>  $media
     */
    private function syncMedia(Record $record, array $media): void
    {
        foreach ($media as $role => $localizedItems) {
            foreach ($localizedItems as $locale => $items) {
                $record->syncMediaForRole($role, $locale, $items);
            }
        }
    }
}
