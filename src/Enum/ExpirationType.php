<?php

declare(strict_types=1);

namespace App\Enum;

enum ExpirationType: string
{
    case Fixed = 'fixed';
    case Seasonal = 'seasonal';
}
