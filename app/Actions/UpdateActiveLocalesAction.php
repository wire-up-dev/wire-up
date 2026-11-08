<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEvent;
use App\Models\Locale;
use App\Services\ActivityLog;
use App\Services\PageCache;
use Illuminate\Support\Facades\DB;

final readonly class UpdateActiveLocalesAction
{
    /**
     * @param  array<int, string>  $codes
     * @return array<int, string>
     */
    public function handle(array $codes): array
    {
        $default = resolve('localization')->getDefaultLocale();
        $codes = array_values(array_unique([...$codes, $default]));

        /** @var array<int, string> $before */
        $before = Locale::query()->where('active', true)->orderBy('code')->pluck('code')->all();

        DB::transaction(function () use ($codes): void {
            Locale::query()->whereIn('code', $codes)->update(['active' => true]);
            Locale::query()->whereNotIn('code', $codes)->update(['active' => false]);
        });

        cache()->forget('site-locales');

        $added = array_values(array_diff($codes, $before));
        $removed = array_values(array_diff($before, $codes));

        if ($added !== [] || $removed !== []) {
            resolve(PageCache::class)->flush();
            ActivityLog::record(ActivityEvent::LANGUAGES_UPDATED, implode(', ', $codes), ['added' => $added, 'removed' => $removed]);
        }

        return $codes;
    }
}
