<?php

declare(strict_types=1);

namespace App\Enum;

enum OrderItemType: string
{
    case Package = 'package';
    case AddAmount = 'add_amount';
    case RemoveAmount = 'remove_amount';
    case Commission = 'commission';
    case PreRegistrationFee = 'pre_registration_fee';
}
