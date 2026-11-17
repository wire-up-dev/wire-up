<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BookingClaimFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property-read int $id
 * @property-read int $booking_id
 * @property-read CarbonImmutable $starts_at
 */
#[WithoutTimestamps]
final class BookingClaim extends Model
{
    /** @use HasFactory<BookingClaimFactory> */
    use HasFactory;

    public const int CELL_MINUTES = 5;

    /**
     * @return list<CarbonImmutable>
     */
    public static function cellsFor(CarbonImmutable $start, int $minutes): array
    {
        $cells = [];

        for ($offset = 0; $offset < $minutes; $offset += self::CELL_MINUTES) {
            $cells[] = $start->utc()->addMinutes($offset);
        }

        return $cells;
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'integer',
            'booking_id' => 'integer',
            'starts_at' => 'immutable_datetime',
        ];
    }
}
