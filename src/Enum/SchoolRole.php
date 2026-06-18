<?php

declare(strict_types=1);

namespace App\Enum;

enum SchoolRole: string
{
    case Student = 'student';
    case Teacher = 'teacher';
    case Admin = 'admin';
    case Owner = 'owner';
}
