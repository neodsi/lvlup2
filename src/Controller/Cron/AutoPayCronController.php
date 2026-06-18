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
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

class AutoPayCronController extends CronController
{
    private const CHUNK_SIZE = 10;

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
     * GET /crons/auto-pay-schedules
     * Load SEPA payment schedules due today and trigger a Stripe charge per schedule in chunks of 10.
     */
    #[Route('/crons/auto-pay-schedules', name: 'cron_auto_pay_schedules', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $this->checkCronAuth($request);
        } catch (AccessDeniedException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 401);
        }

        $today = new \DateTimeImmutable('today');
        $end   = new \DateTimeImmutable('today 23:59:59');

        // Load all pending payment schedules due today.
        // Auto-pay eligibility (SEPA / customer balance) is determined per-school
        // at charge time via the school's Stripe setup — not stored on PaymentSchedule.
        $schedules = $this->em->createQueryBuilder()
            ->select('ps')
            ->from(PaymentSchedule::class, 'ps')
            ->where('ps.status = :status')
            ->andWhere('ps.dueAt >= :start')
            ->andWhere('ps.dueAt <= :end')
            ->setParameter('status', ScheduleStatus::Pending->value)
            ->setParameter('start', $today)
            ->setParameter('end', $end)
            ->getQuery()
            ->getResult();

        $chunks    = array_chunk($schedules, self::CHUNK_SIZE);
        $charged   = 0;
        $failed    = 0;

        foreach ($chunks as $chunk) {
            foreach ($chunk as $schedule) {
                try {
                    $this->chargeSchedule($schedule);
                    ++$charged;
                } catch (\Throwable $e) {
                    ++$failed;
                    $this->logger->error('AutoPayCron: failed to charge schedule', [
                        'schedule_id' => $schedule->getId(),
                        'error'       => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->logger->info('AutoPayCron: completed', [
            'total'   => count($schedules),
            'charged' => $charged,
            'failed'  => $failed,
        ]);

        return new JsonResponse([
            'success' => true,
            'total'   => count($schedules),
            'charged' => $charged,
            'failed'  => $failed,
        ]);
    }

    private function chargeSchedule(PaymentSchedule $schedule): void
    {
        $school = $this->em->getRepository(School::class)->find($schedule->getSchoolId());

        if ($school === null || $school->getStripeAccountId() === null) {
            throw new \RuntimeException(sprintf('School or Stripe account not found for schedule "%s".', $schedule->getId()));
        }

        $profile = $this->em->getRepository(Profile::class)->find($schedule->getProfileId());

        if ($profile === null) {
            throw new \RuntimeException(sprintf('Profile not found for schedule "%s".', $schedule->getId()));
        }

        // Trigger Stripe charge via PaymentIntent on the connected account
        $paymentIntent = PaymentIntent::create(
            [
                'amount'               => $schedule->getAmount(),
                'currency'             => strtolower($school->getCurrency()),
                'payment_method_types' => ['sepa_debit'],
                'confirm'              => true,
                'metadata'             => [
                    'schedule_id' => $schedule->getId(),
                    'team_id'     => $school->getId(),
                    'profile_id'  => $profile->getId(),
                ],
            ],
            ['stripe_account' => $school->getStripeAccountId()],
        );

        // Create a Payment record
        $payment = new Payment();
        $payment->setOrderId($schedule->getOrderId());
        $payment->setSchoolId($schedule->getSchoolId());
        $payment->setProfileId($schedule->getProfileId());
        $payment->setAmount($schedule->getAmount());
        $payment->setMethod(PaymentMethod::OnlineStripeSepaDebit);
        $payment->setStripePaymentIntentId($paymentIntent->id);

        if ($paymentIntent->status === 'succeeded') {
            $payment->setPaidAt(new \DateTimeImmutable());
            $schedule->setStatus(ScheduleStatus::Paid);
        }

        $schedule->setPaymentId($payment->getId());
        $schedule->setLastRetryAt(new \DateTimeImmutable());

        $this->em->persist($payment);
        $this->em->persist($schedule);
        $this->em->flush();
    }
}
