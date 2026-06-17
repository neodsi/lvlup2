<?php

declare(strict_types=1);

namespace App\Enum;

enum TeamStatus: string
{
    case Waiting  = 'waiting';
    case Accepted = 'accepted';
    case Refused  = 'refused';
    case Disabled = 'disabled';
}
