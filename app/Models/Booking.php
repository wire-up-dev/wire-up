<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingActor;
use App\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property-read int $id
 * @property-read string $reference
 * @property-read string $manage_token
 * @property-read BookingStatus $status
 * @property-read BookingActor $source
 * @property-read int|null $record_id
 * @property-read string $service_name
 * @property-read int $duration_minutes
 * @property-read int $buffer_minutes
 * @property-read CarbonImmutable $starts_at
 * @property-read CarbonImmutable $ends_at
 * @property-read string|null $name
 * @property-read string|null $email
 * @property-read string|null $phone
 * @property-read string|null $notes
 * @property-read string $locale
 * @property-read string $timezone
 * @property-read int|null $user_id
 * @property-read int|null $order_id
 * @property-read bool $clashed
 * @property-read int $sequence
 * @property-read CarbonInterface|null $confirmed_at
 * @property-read CarbonInterface|null $reminded_at
 * @property-read CarbonInterface|null $cancelled_at
 * @property-read BookingActor|null $cancelled_by
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read Order|null $order
 * @property-read Collection<int, BookingClaim> $claims
 */
final class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory, MassPrunable;

    public static function newReference(): string
    {
        do {
            $reference = Str::upper(Str::random(8));
        } while (self::query()->where('reference', $reference)->exists());

        return $reference;
    }

    public static function newManageToken(): string
    {
        return Str::random(48);
    }

    public static function hasAny(): bool
    {
        return once(fn (): bool => self::query()->exists());
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'integer',
            'status' => BookingStatus::class,
            'source' => BookingActor::class,
            'record_id' => 'integer',
            'duration_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'user_id' => 'integer',
            'order_id' => 'integer',
            'clashed' => 'boolean',
            'sequence' => 'integer',
            'confirmed_at' => 'datetime',
            'reminded_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancelled_by' => BookingActor::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<BookingClaim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(BookingClaim::class);
    }

    public function canBeDeleted(): bool
    {
        return ! $this->mayStillBePaid() && ($this->ends_at->isPast() || in_array($this->status, [BookingStatus::CANCELLED, BookingStatus::EXPIRED], true));
    }

    public function mayStillBePaid(): bool
    {
        return $this->status === BookingStatus::PENDING_PAYMENT
            || ($this->status === BookingStatus::EXPIRED && $this->order?->expires_at?->copy()->addMinutes(10)->isFuture() === true);
    }

    public function label(): string
    {
        return $this->name ?: $this->service_name;
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where('status', BookingStatus::EXPIRED)->where('updated_at', '<', now()->subDays(7));
    }
}
