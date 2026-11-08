<?php

declare(strict_types=1);

use Tests\TestCase;

it('allows the suite to run on the sqlite connection', function (): void {
    expect(fn () => TestCase::assertTestDatabaseIsIsolated('sqlite'))
        ->not->toThrow(RuntimeException::class);
});

it('refuses to run the suite against a real database', function (): void {
    config(['database.connections.mysql.database' => 'wireup']);

    expect(fn () => TestCase::assertTestDatabaseIsIsolated('mysql'))
        ->toThrow(RuntimeException::class, 'Refusing to run the test suite');
});

it('allows a mysql or postgres database set aside for tests', function (string $connection): void {
    config(["database.connections.{$connection}.database" => 'wireup_test']);

    expect(fn () => TestCase::assertTestDatabaseIsIsolated($connection))
        ->not->toThrow(RuntimeException::class);
})->with(['mysql', 'pgsql']);
