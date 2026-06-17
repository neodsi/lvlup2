<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Profile;
use App\Entity\Team;
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
     * Create a Stripe Connect Express account for the team and persist the account ID.
     */
    public function createConnectedAccount(Team $team): string
    {
        $account = Account::create([
            'type'  => 'express',
            'email' => null,
            'metadata' => [
                'team_id'   => $team->getId(),
                'team_name' => $team->getName(),
            ],
        ]);

        $team->setStripeAccountId($account->id);
        $team->setStripeAccountStatus(StripeAccountStatus::Pending);
        $this->em->flush();

        return $account->id;
    }

    /**
     * Return the Stripe Connect onboarding URL for the team.
     * Creates the connected account first if it does not exist yet.
     */
    public function getOnboardingLink(Team $team): string
    {
        if ($team->getStripeAccountId() === null) {
            $this->createConnectedAccount($team);
        }

        $link = \Stripe\AccountLink::create([
            'account'     => $team->getStripeAccountId(),
            'refresh_url' => $this->buildAbsoluteUrl('/stripe/onboarding/refresh'),
            'return_url'  => $this->buildAbsoluteUrl('/stripe/onboarding/return'),
            'type'        => 'account_onboarding',
        ]);

        return $link->url;
    }

    /**
     * Fetch the connected account from Stripe and synchronise the team's status
     * and payment capabilities in the database.
     */
    public function updateAccountStatus(Team $team): void
    {
        $accountId = $team->getStripeAccountId();

        if ($accountId === null) {
            return;
        }

        $account = Account::retrieve($accountId);

        $status = $this->resolveAccountStatus($account);
        $team->setStripeAccountStatus($status);

        // Persist capabilities
        $capabilities = [];
        foreach ($account->capabilities->toArray() as $capName => $capStatus) {
            $capabilities[$capName] = $capStatus;
        }
        $team->setStripePaymentCapabilities($capabilities);

        $this->em->flush();
    }

    /**
     * Return the list of currently missing/pending Stripe requirements for the team.
     *
     * @return string[]
     */
    public function getRequirements(Team $team): array
    {
        $accountId = $team->getStripeAccountId();

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
     * Create a Stripe Checkout Session on the team's connected account.
     *
     * @param array<int, array{amount: int, dueAt: \DateTimeImmutable}> $scheduleEntries
     */
    public function createCheckoutSession(
        Order $order,
        Team $team,
        Profile $profile,
        array $scheduleEntries,
        bool $isAutoPay,
    ): string {
        $accountId = $team->getStripeAccountId();

        if ($accountId === null) {
            throw new \LogicException(sprintf(
                'Team "%s" has no Stripe connected account.',
                $team->getId(),
            ));
        }

        $currency   = strtolower($team->getCurrency());
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
                'team_id'    => $team->getId(),
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
        Team $team,
        Profile $profile,
    ): string {
        $accountId = $team->getStripeAccountId();

        if ($accountId === null) {
            throw new \LogicException(sprintf(
                'Team "%s" has no Stripe connected account.',
                $team->getId(),
            ));
        }

        $schedules  = $this->em->getRepository(\App\Entity\PaymentSchedule::class)->findBy(
            ['id' => $scheduleIds],
        );

        if (empty($schedules)) {
            throw new \InvalidArgumentException('No payment schedules found for the provided IDs.');
        }

        $currency  = strtolower($team->getCurrency());
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
                    'team_id'    => $team->getId(),
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
     * Refund a payment (partially or fully) via the team's connected Stripe account.
     */
    public function refundPayment(Payment $payment, int $amount, Team $team): void
    {
        $accountId = $team->getStripeAccountId();

        if ($accountId === null) {
            throw new \LogicException(sprintf(
                'Team "%s" has no Stripe connected account.',
                $team->getId(),
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
