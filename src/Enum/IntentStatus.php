<?php

declare(strict_types=1);

namespace App\Enum;

enum IntentStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
}
