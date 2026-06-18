<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Profile;
use App\Entity\School;
use App\Enum\StripeAccountStatus;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Account;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentLink;
use Stripe\Price;
use Stripe\Product;
use Stripe\Refund;
use Stripe\Stripe;

class StripeService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $stripeSecretKey,
    ) {
        Stripe::setApiKey($this->stripeSecretKey);
    }

    // -------------------------------------------------------------------------
    // Connected accounts
    // -------------------------------------------------------------------------

    /**
     * Create a Stripe Connect Express account for the school and persist the account ID.
     */
    public function createConnectedAccount(School $school): string
    {
        $account = Account::create([
            'type'  => 'express',
            'email' => null,
            'metadata' => [
                'team_id'   => $school->getId(),
                'team_name' => $school->getName(),
            ],
        ]);

        $school->setStripeAccountId($account->id);
        $school->setStripeAccountStatus(StripeAccountStatus::Pending);
        $this->em->flush();

        return $account->id;
    }

    /**
     * Return the Stripe Connect onboarding URL for the school.
     * Creates the connected account first if it does not exist yet.
     */
    public function getOnboardingLink(School $school): string
    {
        if ($school->getStripeAccountId() === null) {
            $this->createConnectedAccount($school);
        }

        $link = \Stripe\AccountLink::create([
            'account'     => $school->getStripeAccountId(),
            'refresh_url' => $this->buildAbsoluteUrl('/stripe/onboarding/refresh'),
            'return_url'  => $this->buildAbsoluteUrl('/stripe/onboarding/return'),
            'type'        => 'account_onboarding',
        ]);

        return $link->url;
    }

    /**
     * Fetch the connected account from Stripe and synchronise the school's status
     * and payment capabilities in the database.
     */
    public function updateAccountStatus(School $school): void
    {
        $accountId = $school->getStripeAccountId();

        if ($accountId === null) {
            return;
        }

        $account = Account::retrieve($accountId);

        $status = $this->resolveAccountStatus($account);
        $school->setStripeAccountStatus($status);

        // Persist capabilities
        $capabilities = [];
        foreach ($account->capabilities->toArray() as $capName => $capStatus) {
            $capabilities[$capName] = $capStatus;
        }
        $school->setStripePaymentCapabilities($capabilities);

        $this->em->flush();
    }

    /**
     * Return the list of currently missing/pending Stripe requirements for the school.
     *
     * @return string[]
     */
    public function getRequirements(School $school): array
    {
        $accountId = $school->getStripeAccountId();

        if ($accountId === null) {
            return ['stripe_account_not_created'];
        }

        $account = Account::retrieve($accountId);

        $requirements = $account->requirements ?? null;

        if ($requirements === null) {
            return [];
        }

        return array_merge(
            $requirements->currently_due ?? [],
            $requirements->eventually_due ?? [],
            $requirements->past_due ?? [],
        );
    }

    // -------------------------------------------------------------------------
    // Checkout
    // -------------------------------------------------------------------------

    /**
     * Create a Stripe Checkout Session on the school's connected account.
     *
     * @param array<int, array{amount: int, dueAt: \DateTimeImmutable}> $scheduleEntries
     */
    public function createCheckoutSession(
        Order $order,
        School $school,
        Profile $profile,
        array $scheduleEntries,
        bool $isAutoPay,
    ): string {
        $accountId = $school->getStripeAccountId();

        if ($accountId === null) {
            throw new \LogicException(sprintf(
                'School "%s" has no Stripe connected account.',
                $school->getId(),
            ));
        }

        $currency   = strtolower($school->getCurrency());
        $lineItems  = [];

        foreach ($scheduleEntries as $entry) {
            $lineItems[] = [
                'price_data' => [
                    'currency'     => $currency,
                    'unit_amount'  => $entry['amount'],
                    'product_data' => [
                        'name' => sprintf(
                            'Paiement %s',
                            $entry['dueAt']->format('d/m/Y'),
                        ),
                    ],
                ],
                'quantity' => 1,
            ];
        }

        $sessionParams = [
            'payment_method_types' => ['card'],
            'mode'                 => 'payment',
            'line_items'           => $lineItems,
            'success_url'          => $this->buildAbsoluteUrl(
                sprintf('/order/%s/success?session_id={CHECKOUT_SESSION_ID}', $order->getId()),
            ),
            'cancel_url' => $this->buildAbsoluteUrl(
                sprintf('/order/%s/cancel', $order->getId()),
            ),
            'metadata' => [
                'order_id'   => $order->getId(),
                'profile_id' => $profile->getId(),
                'team_id'    => $school->getId(),
            ],
        ];

        if ($isAutoPay) {
            $sessionParams['payment_method_types'] = ['card', 'sepa_debit'];
            $sessionParams['mode']                 = 'setup';
            unset($sessionParams['line_items']);
        }

        $session = Session::create(
            $sessionParams,
            ['stripe_account' => $accountId],
        );

        return $session->url ?? throw new \RuntimeException('Stripe checkout session URL is null.');
    }

    /**
     * Create a Stripe Payment Link for a list of existing schedule IDs.
     *
     * @param string[] $scheduleIds
     */
    public function getPaymentLinkForSchedules(
        array $scheduleIds,
        School $school,
        Profile $profile,
    ): string {
        $accountId = $school->getStripeAccountId();

        if ($accountId === null) {
            throw new \LogicException(sprintf(
                'School "%s" has no Stripe connected account.',
                $school->getId(),
            ));
        }

        $schedules  = $this->em->getRepository(\App\Entity\PaymentSchedule::class)->findBy(
            ['id' => $scheduleIds],
        );

        if (empty($schedules)) {
            throw new \InvalidArgumentException('No payment schedules found for the provided IDs.');
        }

        $currency  = strtolower($school->getCurrency());
        $lineItems = [];

        foreach ($schedules as $schedule) {
            $product = Product::create(
                [
                    'name'     => sprintf('Echeance %s', $schedule->getDueAt()->format('d/m/Y')),
                    'metadata' => ['schedule_id' => $schedule->getId()],
                ],
                ['stripe_account' => $accountId],
            );

            $price = Price::create(
                [
                    'unit_amount' => $schedule->getAmount(),
                    'currency'    => $currency,
                    'product'     => $product->id,
                ],
                ['stripe_account' => $accountId],
            );

            $lineItems[] = ['price' => $price->id, 'quantity' => 1];
        }

        $link = PaymentLink::create(
            [
                'line_items' => $lineItems,
                'metadata'   => [
                    'profile_id' => $profile->getId(),
                    'team_id'    => $school->getId(),
                ],
            ],
            ['stripe_account' => $accountId],
        );

        return $link->url ?? throw new \RuntimeException('Stripe payment link URL is null.');
    }

    // -------------------------------------------------------------------------
    // Refunds
    // -------------------------------------------------------------------------

    /**
     * Refund a payment (partially or fully) via the school's connected Stripe account.
     */
    public function refundPayment(Payment $payment, int $amount, School $school): void
    {
        $accountId = $school->getStripeAccountId();

        if ($accountId === null) {
            throw new \LogicException(sprintf(
                'School "%s" has no Stripe connected account.',
                $school->getId(),
            ));
        }

        $paymentIntentId = $payment->getStripePaymentIntentId();

        if ($paymentIntentId === null) {
            throw new \InvalidArgumentException(sprintf(
                'Payment "%s" has no Stripe payment intent ID.',
                $payment->getId(),
            ));
        }

        Refund::create(
            [
                'payment_intent' => $paymentIntentId,
                'amount'         => $amount,
            ],
            ['stripe_account' => $accountId],
        );

        $currentRefund = $payment->getRefundAmount();
        $payment->setRefundAmount($currentRefund + $amount);
        $payment->setRefundedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function resolveAccountStatus(Account $account): StripeAccountStatus
    {
        if ($account->details_submitted && ($account->charges_enabled ?? false)) {
            return StripeAccountStatus::Active;
        }

        if ($account->details_submitted) {
            return StripeAccountStatus::Restricted;
        }

        return StripeAccountStatus::Pending;
    }

    /**
     * Build an absolute URL from a path.
     * In a real application this would use UrlGeneratorInterface.
     * Here we fall back to a basic env-driven base URL.
     */
    private function buildAbsoluteUrl(string $path): string
    {
        $base = rtrim($_ENV['APP_URL'] ?? 'https://app.lvlup.com', '/');

        return $base . '/' . ltrim($path, '/');
    }
}
