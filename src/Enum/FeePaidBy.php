<?php

declare(strict_types=1);

namespace App\Enum;

enum FeePaidBy: string
{
    case Student = 'student';
    case School    = 'school';
}
