<?php

declare(strict_types=1);

namespace App\Enum;

enum PackageType: string
{
    case SubscriptionOneYear = 'subscription_one_year';
    case SubscriptionOneSemester = 'subscription_one_semester';
    case TrialClass = 'trial_class';
    case ALaCarte = 'a_la_carte';
}
