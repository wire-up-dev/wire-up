<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Page;
use App\Services\ActivityLog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class UpdatePageAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Page $page, array $attributes): void
    {
        DB::transaction(function () use ($page, $attributes): void {
            $page->recordBaselineRevision();
            $previousStatus = $page->computed_status;

            $redirects = new RedirectMovedContentAction();
            $previousPaths = isset($attributes['slugs']) ? $redirects->livePaths($page) : [];

            $page->update([
                ...Arr::except($attributes, ['slugs', 'status', 'og_image', 'blocks', 'categories']),
                ...$page->publicationAttributes($attributes),
                'updated_at' => now(),
            ]);

            if (isset($attributes['slugs'])) {
                $page->updateSlugs($attributes['slugs']);
                $redirects->handle($page, $previousPaths);
            }

            if (isset($attributes['blocks'])) {
                $page->updateBlocks($attributes['blocks']);
            }

            if (isset($attributes['og_image'])) {
                $this->syncMedia($page, 'og_image', $attributes['og_image']);
            }

            if (isset($attributes['categories'])) {
                $page->categories()->sync($attributes['categories']);
            }

            ActivityLog::contentSaved($page, $previousStatus, $page->recordRevision());
        });
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $localizedItems
     */
    private function syncMedia(Page $page, string $role, array $localizedItems): void
    {
        foreach ($localizedItems as $locale => $items) {
            $page->syncMediaForRole($role, $locale, $items);
        }
    }
}
