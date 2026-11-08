<?php

declare(strict_types=1);

use App\Console\Commands\PruneStaleDataCommand;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

function storedConversation(string $id, DateTimeInterface $touchedAt): void
{
    DB::table('agent_conversations')->insert(['id' => $id, 'participant_type' => 'user', 'participant_id' => 1, 'title' => 'Chat', 'created_at' => $touchedAt, 'updated_at' => $touchedAt]);
    DB::table('agent_conversation_messages')->insert(['id' => $id.'-m', 'conversation_id' => $id, 'participant_type' => 'user', 'participant_id' => 1, 'agent' => 'site', 'role' => 'user', 'content' => 'Hi', 'attachments' => '[]', 'steps' => '[]', 'status' => 'completed', 'usage' => '[]', 'meta' => '[]', 'created_at' => $touchedAt, 'updated_at' => $touchedAt]);
}

it('deletes assistant conversations untouched for six months and keeps recent ones', function (): void {
    storedConversation('old-chat', now()->subDays(PruneStaleDataCommand::CONVERSATION_DAYS + 1));
    storedConversation('recent-chat', now()->subDays(10));

    $this->artisan('wireup:prune-stale-data')
        ->expectsOutputToContain('1 old assistant conversations')
        ->assertSuccessful();

    expect(DB::table('agent_conversations')->pluck('id')->all())->toBe(['recent-chat'])
        ->and(DB::table('agent_conversation_messages')->pluck('conversation_id')->all())->toBe(['recent-chat']);
});

it('clears expired entries from the database cache store and keeps live ones', function (): void {
    config(['cache.default' => 'database']);
    DB::table('cache')->insert([
        ['key' => 'expired', 'value' => 's:1:"x";', 'expiration' => now()->subMinute()->getTimestamp()],
        ['key' => 'live', 'value' => 's:1:"y";', 'expiration' => now()->addHour()->getTimestamp()],
    ]);

    $this->artisan('wireup:prune-stale-data')
        ->expectsOutputToContain('Deleted 1 expired cache entries')
        ->assertSuccessful();

    expect(DB::table('cache')->pluck('key')->all())->toBe(['live']);
});

it('runs every day alongside pruning failed jobs', function (): void {
    $this->artisan('schedule:list')->assertExitCode(0);

    $commands = collect(resolve(Schedule::class)->events())->map(fn (Event $event): string => (string) $event->command);

    expect($commands->contains(fn (string $command): bool => str_contains($command, 'wireup:prune-stale-data')))->toBeTrue()
        ->and($commands->contains(fn (string $command): bool => str_contains($command, 'queue:prune-failed') && str_contains($command, '720')))->toBeTrue();
});
