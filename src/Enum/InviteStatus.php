<?php

declare(strict_types=1);

namespace App\Enum;

enum InviteStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Refused = 'refused';
    case Expired = 'expired';
}
