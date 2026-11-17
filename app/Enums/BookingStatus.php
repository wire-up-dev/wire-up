<?php

declare(strict_types=1);

namespace App\Enums;

enum BookingStatus: string
{
    case PENDING_PAYMENT = 'pending_payment';
    case CONFIRMED = 'confirmed';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::PENDING_PAYMENT => __('Awaiting payment'),
            self::CONFIRMED => __('Confirmed'),
            self::CANCELLED => __('Cancelled'),
            self::EXPIRED => __('Not paid'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING_PAYMENT => 'amber',
            self::CONFIRMED => 'green',
            self::CANCELLED, self::EXPIRED => 'zinc',
        };
    }

    public function holdsTime(): bool
    {
        return in_array($this, [self::PENDING_PAYMENT, self::CONFIRMED], true);
    }
}
