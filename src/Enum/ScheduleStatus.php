<?php

declare(strict_types=1);

namespace App\Enum;

enum ScheduleStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
