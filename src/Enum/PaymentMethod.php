<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentMethod: string
{
    case OnsiteCash = 'onsite_cash';
    case OnsiteCheck = 'onsite_check';
    case OnsiteTransfer = 'onsite_transfer';
    case OnlineStripeCheckout = 'online_stripe_checkout';
    case OnlineStripeCustomerBalance = 'online_stripe_customer_balance';
    case OnlineStripeSepaDebit = 'online_stripe_sepa_debit';
    case OnlineStripeLink = 'online_stripe_link';
}
