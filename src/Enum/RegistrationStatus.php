<?php

declare(strict_types=1);

namespace App\Enum;

enum RegistrationStatus: string
{
    case NotRegistered = 'not_registered';
    case PreRegistered = 'pre_registered';
    case Registered = 'registered';
    case Withdrawn = 'withdrawn';
}
