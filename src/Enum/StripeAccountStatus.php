<?php

declare(strict_types=1);

namespace App\Enum;

enum StripeAccountStatus: string
{
    case NotCreated = 'not_created';
    case Pending    = 'pending';
    case Active     = 'active';
    case Restricted = 'restricted';
}
