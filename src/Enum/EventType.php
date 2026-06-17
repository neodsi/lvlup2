<?php

declare(strict_types=1);

namespace App\Enum;

enum EventType: string
{
    case Lesson = 'lesson';
    case Stage = 'stage';
    case Gala = 'gala';
    case Workshop = 'workshop';
    case Other = 'other';
}
