<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Description('Delete expired cache entries and assistant conversations nobody has opened for six months')]
#[Signature('wireup:prune-stale-data')]
final class PruneStaleDataCommand extends Command
{
    public const int CONVERSATION_DAYS = 180;

    public function handle(): int
    {
        $cacheRows = config('cache.default') === 'database'
            ? DB::table(config()->string('cache.stores.database.table', 'cache'))->where('expiration', '<=', now()->getTimestamp())->delete()
            : 0;

        $conversations = DB::table(config()->string('ai.conversations.tables.conversations', 'agent_conversations'))
            ->where('updated_at', '<', now()->subDays(self::CONVERSATION_DAYS))
            ->pluck('id');

        foreach ($conversations->chunk(500) as $ids) {
            DB::table(config()->string('ai.conversations.tables.messages', 'agent_conversation_messages'))->whereIn('conversation_id', $ids)->delete();
            DB::table(config()->string('ai.conversations.tables.conversations', 'agent_conversations'))->whereIn('id', $ids)->delete();
        }

        $this->components->info("Deleted {$cacheRows} expired cache entries and {$conversations->count()} old assistant conversations.");

        return self::SUCCESS;
    }
}
