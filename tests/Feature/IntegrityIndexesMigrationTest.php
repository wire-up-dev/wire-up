<?php

declare(strict_types=1);

use App\Models\Page;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('keeps the oldest slug when one page holds several for a language, then forbids it', function (): void {
    $page = Page::factory()->create();
    $page->slugs()->delete();
    Schema::table('slugs', fn (Blueprint $table) => $table->dropUnique('slugs_sluggable_locale_unique'));

    $row = ['sluggable_type' => 'page', 'sluggable_id' => $page->id, 'locale' => 'en', 'base_path' => '', 'created_at' => now(), 'updated_at' => now()];
    $kept = DB::table('slugs')->insertGetId([...$row, 'slug' => 'first']);
    DB::table('slugs')->insert([...$row, 'slug' => 'second']);

    (require database_path('migrations/2026_11_08_034332_add_integrity_indexes.php'))->up();

    expect(DB::table('slugs')->where('sluggable_id', $page->id)->pluck('id')->all())->toBe([$kept])
        ->and(Schema::hasIndex('slugs', 'slugs_sluggable_locale_unique'))->toBeTrue()
        ->and(Schema::hasIndex('mediables', ['media_id']))->toBeTrue()
        ->and(Schema::hasIndex('mediables', 'mediable'))->toBeFalse();
});
