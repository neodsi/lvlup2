<?php

declare(strict_types=1);

namespace App\Enum;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Unknown = 'unknown';
}
