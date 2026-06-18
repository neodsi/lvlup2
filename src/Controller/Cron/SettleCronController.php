<?php

declare(strict_types=1);

namespace App\Controller\Cron;

use App\Entity\Payment;
use App\Entity\PaymentSchedule;
use App\Entity\Profile;
use App\Entity\School;
use App\Enum\PaymentMethod;
use App\Enum\ScheduleStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class SettleCronController extends CronController
{
    public function __construct(
        string $cronSecret,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $stripeSecretKey,
    ) {
        parent::__construct($cronSecret);
        Stripe::setApiKey($this->stripeSecretKey);
    }

    /**
     * GET /crons/settle-customer-balances
     * Find positive Stripe customer balances and apply them to pending payment schedules.
     */
    #[Route('/crons/settle-customer-balances', name: 'cron_settle_customer_balances', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $this->checkCronAuth($request);
        } catch (AccessDeniedException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 401);
        }

        // Load all schools with a connected Stripe account
        /** @var School[] $schools */
        $schools = $this->em->createQueryBuilder()
            ->select('t')
            ->from(School::class, 't')
            ->where('t.stripeAccountId IS NOT NULL')
            ->getQuery()
            ->getResult();

        $settled = 0;
        $errors  = [];

        foreach ($schools as $school) {
            try {
                $schoolSettled = $this->settleSchoolBalances($school);
                $settled    += $schoolSettled;
            } catch (\Throwable $e) {
                $errors[] = sprintf('School "%s": %s', $school->getId(), $e->getMessage());
                $this->logger->error('SettleCron: failed to settle balances for school', [
                    'team_id' => $school->getId(),
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('SettleCron: completed', ['settled' => $settled, 'errors' => count($errors)]);

        return new JsonResponse([
            'success' => true,
            'settled' => $settled,
            'errors'  => $errors,
        ]);
    }

    private function settleSchoolBalances(School $school): int
    {
        $accountId = $school->getStripeAccountId();
        $settled   = 0;

        // Fetch all Stripe customers for this connected account
        $customers = Customer::all(
            ['limit' => 100],
            ['stripe_account' => $accountId],
        );

        foreach ($customers->autoPagingIterator() as $customer) {
            // Stripe stores balance as negative = credit (customer is owed money)
            // A positive balance means the customer owes money; a negative balance is a credit.
            // We want to apply credit (negative balance) to pending schedules.
            $balanceCredit = -($customer->balance ?? 0);

            if ($balanceCredit <= 0) {
                continue;
            }

            // Find the profile linked to this Stripe customer
            $profileId = $customer->metadata['profile_id'] ?? null;

            if ($profileId === null) {
                continue;
            }

            $profile = $this->em->getRepository(Profile::class)->find($profileId);

            if ($profile === null) {
                continue;
            }

            // Find pending schedules for this profile, ordered by due date ascending
            /** @var PaymentSchedule[] $pendingSchedules */
            $pendingSchedules = $this->em->createQueryBuilder()
                ->select('ps')
                ->from(PaymentSchedule::class, 'ps')
                ->where('ps.profileId = :profileId')
                ->andWhere('ps.schoolId = :schoolId')
                ->andWhere('ps.status = :status')
                ->orderBy('ps.dueAt', 'ASC')
                ->setParameter('profileId', $profileId)
                ->setParameter('schoolId', $school->getId())
                ->setParameter('status', ScheduleStatus::Pending->value)
                ->getQuery()
                ->getResult();

            $remainingCredit = $balanceCredit;

            foreach ($pendingSchedules as $schedule) {
                if ($remainingCredit <= 0) {
                    break;
                }

                $amountToSettle = min($remainingCredit, $schedule->getAmount());

                try {
                    // Apply customer balance via PaymentIntent
                    $paymentIntent = PaymentIntent::create(
                        [
                            'amount'               => $amountToSettle,
                            'currency'             => strtolower($school->getCurrency()),
                            'customer'             => $customer->id,
                            'payment_method_types' => ['customer_balance'],
                            'payment_method_data'  => ['type' => 'customer_balance'],
                            'confirm'              => true,
                            'metadata'             => [
                                'schedule_id' => $schedule->getId(),
                                'team_id'     => $school->getId(),
                                'profile_id'  => $profileId,
                            ],
                        ],
                        ['stripe_account' => $accountId],
                    );

                    // Record the payment
                    $payment = new Payment();
                    $payment->setOrderId($schedule->getOrderId());
                    $payment->setSchoolId($school->getId());
                    $payment->setProfileId($profileId);
                    $payment->setAmount($amountToSettle);
                    $payment->setMethod(PaymentMethod::OnlineStripeCustomerBalance);
                    $payment->setStripePaymentIntentId($paymentIntent->id);

                    if ($paymentIntent->status === 'succeeded') {
                        $payment->setPaidAt(new \DateTimeImmutable());
                        $schedule->setStatus(ScheduleStatus::Paid);
                    }

                    $schedule->setPaymentId($payment->getId());

                    $this->em->persist($payment);
                    $this->em->persist($schedule);
                    $this->em->flush();

                    $remainingCredit -= $amountToSettle;
                    ++$settled;
                } catch (\Throwable $e) {
                    $this->logger->error('SettleCron: failed to apply balance to schedule', [
                        'schedule_id' => $schedule->getId(),
                        'customer_id' => $customer->id,
                        'error'       => $e->getMessage(),
                    ]);
                }
            }
        }

        return $settled;
    }
}
