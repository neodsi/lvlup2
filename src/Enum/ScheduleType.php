<?php

declare(strict_types=1);

namespace App\Enum;

enum ScheduleType: string
{
    case Recurring = 'recurring';
    case FixedDates = 'fixed_dates';
}
