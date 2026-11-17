<?php

declare(strict_types=1);

namespace App\Enums;

enum BookingActor: string
{
    case CUSTOMER = 'customer';
    case OWNER = 'owner';
    case SYSTEM = 'system';
}
