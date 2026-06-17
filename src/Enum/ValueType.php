<?php

declare(strict_types=1);

namespace App\Enum;

enum ValueType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
}
