<?php

declare(strict_types=1);

use App\Models\Locale;
use App\Models\Page;
use App\Models\Record;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('refuses to delete a content type that still has records', function (): void {
    $record = Record::factory()->create();

    expect(fn () => DB::transaction(fn (): int => DB::table('record_types')->where('id', $record->record_type_id)->delete()))->toThrow(QueryException::class)
        ->and(Record::query()->whereKey($record->id)->exists())->toBeTrue();
});

it('refuses to delete a language that still has content', function (): void {
    Locale::query()->updateOrCreate(['code' => 'fr'], ['name' => 'French', 'active' => true]);
    Page::factory()->create()->slugs()->create(['locale' => 'fr', 'slug' => 'bonjour', 'base_path' => '']);

    expect(fn () => DB::transaction(fn (): int => DB::table('locales')->where('code', 'fr')->delete()))->toThrow(QueryException::class)
        ->and(DB::table('slugs')->where('locale', 'fr')->exists())->toBeTrue();
});

it('restricts every content foreign key that used to cascade', function (string $table, string $column): void {
    $foreignKey = collect(Schema::getForeignKeys($table))->first(fn (array $key): bool => $key['columns'] === [$column]);

    expect($foreignKey['on_delete'])->toBe('restrict');
})->with([
    ['records', 'record_type_id'],
    ['translations', 'locale'],
    ['slugs', 'locale'],
    ['mediables', 'locale'],
]);
