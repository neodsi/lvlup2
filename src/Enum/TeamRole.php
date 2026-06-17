<?php

declare(strict_types=1);

namespace App\Enum;

enum TeamRole: string
{
    case TeamStudent = 'team_student';
    case TeamTeacher = 'team_teacher';
    case TeamAdmin = 'team_admin';
    case TeamOwner = 'team_owner';
}
