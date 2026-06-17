<?php

declare(strict_types=1);

namespace App\Enum;

enum ValidityStartType: string
{
    case AtAttribution = 'at_attribution';
    case FixedDate = 'fixed_date';
}
