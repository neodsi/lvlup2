<?php

declare(strict_types=1);

namespace App\Enum;

enum TeamProfileStatus: string
{
    case Waiting = 'waiting';
    case Accepted = 'accepted';
    case Refused = 'refused';
    case Suspended = 'suspended';
}
