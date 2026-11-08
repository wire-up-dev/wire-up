<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, array{column: string, on: string, references: string}>
     */
    private const array RESTRICTED_FOREIGN_KEYS = [
        'records' => ['column' => 'record_type_id', 'on' => 'record_types', 'references' => 'id'],
        'translations' => ['column' => 'locale', 'on' => 'locales', 'references' => 'code'],
        'slugs' => ['column' => 'locale', 'on' => 'locales', 'references' => 'code'],
        'mediables' => ['column' => 'locale', 'on' => 'locales', 'references' => 'code'],
    ];

    public function up(): void
    {
        foreach (self::RESTRICTED_FOREIGN_KEYS as $table => $foreignKey) {
            Schema::table($table, function (Blueprint $blueprint) use ($foreignKey): void {
                $blueprint->dropForeign([$foreignKey['column']]);
                $blueprint->foreign($foreignKey['column'])->references($foreignKey['references'])->on($foreignKey['on'])->restrictOnDelete();
            });
        }
    }
};
