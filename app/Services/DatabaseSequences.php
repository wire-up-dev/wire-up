<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Connection;

final class DatabaseSequences
{
    /**
     * @param  array<int, string>  $tables
     */
    public function reset(Connection $connection, array $tables): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($tables as $table) {
            if (! $connection->getSchemaBuilder()->hasColumn($table, 'id')) {
                continue;
            }

            $sequence = "pg_get_serial_sequence('{$connection->getTablePrefix()}{$table}', 'id')";

            $connection->statement(
                "select setval({$sequence}, greatest(coalesce((select max(id) from {$connection->getQueryGrammar()->wrapTable($table)}), 0) + 1, nextval({$sequence})), false)",
            );
        }
    }
}
