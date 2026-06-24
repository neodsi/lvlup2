<?php

declare(strict_types=1);

namespace App\Enum;

enum PackageType: string
{
    case SubscriptionOneYear = 'subscription_one_year';
    case SubscriptionOneSemester = 'subscription_one_semester';
    case SubscriptionOneQuarter = 'subscription_one_quarter';
    case SubscriptionOneMonth = 'subscription_one_month';
    case TrialClass = 'trial_class';
    case ALaCarte = 'a_la_carte';
    case RegistrationFee = 'registration_fee';
}
