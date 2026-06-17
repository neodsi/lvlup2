<?php

declare(strict_types=1);

namespace App\Enum;

enum Operation: string
{
    case Add = 'add';
    case Subtract = 'subtract';
}
