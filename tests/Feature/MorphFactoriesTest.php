<?php

declare(strict_types=1);

use App\Models\Mediable;
use App\Models\Slug;
use App\Models\Translation;

it('attaches factory-made slugs, translations and media links to their page', function (): void {
    $slug = Slug::factory()->create();
    $translation = Translation::factory()->create();
    $mediable = Mediable::factory()->create();

    expect($slug->sluggable->slugs->pluck('id'))->toContain($slug->id)
        ->and($translation->translatable->translations->pluck('id'))->toContain($translation->id)
        ->and($mediable->mediable->media()->pluck('media.id'))->toContain($mediable->media_id);
});
