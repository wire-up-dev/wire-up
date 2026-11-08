<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private const array FOREIGN_KEY_COLUMNS = [
        'mediables' => ['media_id'],
        'order_items' => ['order_id'],
        'orders' => ['user_id'],
        'submissions' => ['block_id', 'page_id'],
        'revisions' => ['user_id'],
        'passkeys' => ['user_id'],
        'access_tokens' => ['user_id'],
        'pages' => ['created_by', 'updated_by'],
        'records' => ['created_by', 'updated_by'],
    ];

    /**
     * @var array<string, string>
     */
    private const array REDUNDANT_INDEXES = [
        'mediables' => 'mediable',
        'blocks' => 'blocks_blockable_type_blockable_id_index',
        'revisions' => 'revisions_revisionable_type_revisionable_id_index',
    ];

    public function up(): void
    {
        foreach (self::FOREIGN_KEY_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasIndex($table, [$column])) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($column));
                }
            }
        }

        foreach (self::REDUNDANT_INDEXES as $table => $index) {
            if (Schema::hasIndex($table, $index)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index));
            }
        }

        $this->removeDuplicateSlugs();

        Schema::table('slugs', fn (Blueprint $table) => $table->unique(['sluggable_type', 'sluggable_id', 'locale'], 'slugs_sluggable_locale_unique'));
    }

    private function removeDuplicateSlugs(): void
    {
        DB::table('slugs')
            ->select(['sluggable_type', 'sluggable_id', 'locale', DB::raw('min(id) as kept_id')])
            ->groupBy(['sluggable_type', 'sluggable_id', 'locale'])
            ->havingRaw('count(*) > 1')
            ->get()
            ->each(fn (object $group): int => DB::table('slugs')
                ->where('sluggable_type', $group->sluggable_type)
                ->where('sluggable_id', $group->sluggable_id)
                ->where('locale', $group->locale)
                ->where('id', '!=', $group->kept_id)
                ->delete());
    }
};
