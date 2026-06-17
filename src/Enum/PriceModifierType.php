<?php

declare(strict_types=1);

namespace App\Enum;

enum PriceModifierType: string
{
    case Cart = 'cart';
    case Profile = 'profile';
    case RegistrationFee = 'registration_fee';
}
