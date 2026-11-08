<?php

declare(strict_types=1);

use App\Services\DatabaseSequences;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Schema\Builder as SchemaBuilder;

it('leaves other databases alone, because they move row ids forward by themselves', function (string $driver): void {
    $connection = Mockery::mock(Connection::class);
    $connection->allows('getDriverName')->andReturn($driver);
    $connection->expects('statement')->never();

    new DatabaseSequences()->reset($connection, ['pages']);
})->with(['sqlite', 'mysql', 'mariadb']);

it('moves each postgres id sequence past the highest imported id without ever moving it back', function (): void {
    $connection = Mockery::mock(Connection::class);
    $schema = Mockery::mock(SchemaBuilder::class);

    $connection->allows('getDriverName')->andReturn('pgsql');
    $connection->allows('getSchemaBuilder')->andReturn($schema);
    $connection->allows('getTablePrefix')->andReturn('');
    $connection->allows('getQueryGrammar')->andReturn(new PostgresGrammar($connection));
    $schema->allows('hasColumn')->with('pages', 'id')->andReturnTrue();
    $schema->allows('hasColumn')->with('categorizables', 'id')->andReturnFalse();

    $connection->expects('statement')
        ->once()
        ->with("select setval(pg_get_serial_sequence('pages', 'id'), greatest(coalesce((select max(id) from \"pages\"), 0) + 1, nextval(pg_get_serial_sequence('pages', 'id'))), false)");

    new DatabaseSequences()->reset($connection, ['pages', 'categorizables']);
});
