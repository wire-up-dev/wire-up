<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Process;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Process::preventStrayProcesses();
    }

    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        self::assertTestDatabaseIsIsolated((string) config('database.default'));
    }

    public static function assertTestDatabaseIsIsolated(string $connection): void
    {
        $driver = (string) config("database.connections.{$connection}.driver");

        $database = (string) config("database.connections.{$connection}.database");

        if ($driver === 'sqlite' || str_contains($database, '_test')) {
            return;
        }

        throw new RuntimeException(
            "Refusing to run the test suite against the '{$driver}' connection (database: '{$database}'). "
            .'Tests must run on sqlite — see phpunit.xml. This almost always means a stale config cache is '
            .'overriding the test connection. Run: php artisan config:clear'
        );
    }

    protected function actingAsAdmin(): self
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
            'active' => true,
            'role' => 'owner',
        ]);

        $this->actingAs($user);

        return $this;
    }
}
