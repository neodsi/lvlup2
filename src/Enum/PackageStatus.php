<?php

declare(strict_types=1);

namespace App\Enum;

enum PackageStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Pending = 'pending';
    case Exhausted = 'exhausted';
}
