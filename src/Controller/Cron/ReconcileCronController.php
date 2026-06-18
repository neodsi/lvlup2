<?php

declare(strict_types=1);

namespace App\Controller\Cron;

use App\Entity\Payment;
use App\Entity\PaymentSchedule;
use App\Entity\School;
use App\Enum\ScheduleStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Stripe;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ReconcileCronController extends CronController
{
    private const MAX_CONCURRENT_TEAMS = 10;

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
     * GET /crons/reconcile-stripe-payments
     * For each school with a Stripe account, fetch recent checkout sessions from Stripe,
     * compare with the DB, and update statuses. Processes max 10 schools at a time.
     */
    #[Route('/crons/reconcile-stripe-payments', name: 'cron_reconcile_stripe_payments', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $this->checkCronAuth($request);
        } catch (AccessDeniedException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 401);
        }

        // Load schools with a connected Stripe account, max MAX_CONCURRENT_TEAMS
        /** @var School[] $schools */
        $schools = $this->em->createQueryBuilder()
            ->select('t')
            ->from(School::class, 't')
            ->where('t.stripeAccountId IS NOT NULL')
            ->setMaxResults(self::MAX_CONCURRENT_TEAMS)
            ->getQuery()
            ->getResult();

        $reconciled = 0;
        $updated    = 0;
        $errors     = [];

        foreach ($schools as $school) {
            try {
                $schoolUpdated = $this->reconcileSchool($school);
                $updated    += $schoolUpdated;
                ++$reconciled;
            } catch (\Throwable $e) {
                $errors[] = sprintf('School "%s": %s', $school->getId(), $e->getMessage());
                $this->logger->error('ReconcileCron: failed to reconcile school', [
                    'team_id' => $school->getId(),
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('ReconcileCron: completed', [
            'schools_processed' => $reconciled,
            'payments_updated' => $updated,
            'errors'          => count($errors),
        ]);

        return new JsonResponse([
            'success'          => true,
            'schools_processed'  => $reconciled,
            'payments_updated' => $updated,
            'errors'           => $errors,
        ]);
    }

    private function reconcileSchool(School $school): int
    {
        $accountId = $school->getStripeAccountId();
        $updated   = 0;

        // Fetch the last 100 completed checkout sessions from Stripe for this connected account
        $sessions = StripeSession::all(
            ['limit' => 100, 'status' => 'complete'],
            ['stripe_account' => $accountId],
        );

        foreach ($sessions->autoPagingIterator() as $session) {
            $sessionId = $session->id;

            // Find the matching Payment in DB
            $payment = $this->em->getRepository(Payment::class)->findOneBy([
                'stripeCheckoutSessionId' => $sessionId,
            ]);

            if ($payment === null) {
                continue;
            }

            $changed = false;

            // Mark payment as paid if not already
            if ($payment->getPaidAt() === null && $session->payment_status === 'paid') {
                $payment->setPaidAt(new \DateTimeImmutable());
                $this->em->persist($payment);
                $changed = true;
            }

            // Sync linked schedules
            $schedules = $this->em->getRepository(PaymentSchedule::class)->findBy([
                'paymentId' => $payment->getId(),
            ]);

            foreach ($schedules as $schedule) {
                if ($schedule->getStatus() !== ScheduleStatus::Paid && $session->payment_status === 'paid') {
                    $schedule->setStatus(ScheduleStatus::Paid);
                    $this->em->persist($schedule);
                    $changed = true;
                }
            }

            if ($changed) {
                $this->em->flush();
                ++$updated;
            }
        }

        return $updated;
    }
}
