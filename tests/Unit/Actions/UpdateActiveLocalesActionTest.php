<?php

declare(strict_types=1);

use App\Actions\UpdateActiveLocalesAction;
use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\Locale;
use App\Services\PageCache;
use Illuminate\Support\Facades\Cache;

it('switches the site languages, keeps the default one, clears cached pages and logs the change', function (): void {
    Cache::put(PageCache::VERSION_KEY, 5);

    $codes = resolve(UpdateActiveLocalesAction::class)->handle(['fr', 'de']);

    expect($codes)->toEqualCanonicalizing(['fr', 'de', 'en'])
        ->and(Locale::query()->where('active', true)->pluck('code')->all())->toEqualCanonicalizing(['en', 'fr', 'de'])
        ->and(resolve('localization')->getActiveLocaleCodes()->all())->toEqualCanonicalizing(['en', 'fr', 'de'])
        ->and(Cache::get(PageCache::VERSION_KEY))->toBe(6);

    $activity = Activity::query()->sole();

    expect($activity->event)->toBe(ActivityEvent::LANGUAGES_UPDATED)
        ->and($activity->properties)->toMatchArray(['added' => ['fr', 'de'], 'removed' => []]);
});

it('changes nothing and logs nothing when the languages stay the same', function (): void {
    Cache::put(PageCache::VERSION_KEY, 5);

    resolve(UpdateActiveLocalesAction::class)->handle(['en']);

    expect(Activity::query()->count())->toBe(0)
        ->and(Cache::get(PageCache::VERSION_KEY))->toBe(5);
});
