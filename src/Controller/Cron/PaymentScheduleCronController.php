<?php

declare(strict_types=1);

namespace App\Controller\Cron;

use App\Entity\PaymentSchedule;
use App\Entity\Profile;
use App\Enum\ScheduleStatus;
use App\Service\Email\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class PaymentScheduleCronController extends CronController
{
    /**
     * Day offsets to check relative to today.
     * Negative = before due date (reminder), 0 = due today, positive = overdue.
     */
    private const DAY_OFFSETS = [-3, 0, 3, 10, 15];

    public function __construct(
        string $cronSecret,
        private readonly EntityManagerInterface $em,
        private readonly EmailService $emailService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($cronSecret);
    }

    /**
     * GET /crons/check-payment-schedules
     * Find payment schedules at J-3, J0, J+3, J+10, J+15 and send reminder emails.
     * Exclusion lists are read from DB, never hardcoded.
     */
    #[Route('/crons/check-payment-schedules', name: 'cron_check_payment_schedules', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $this->checkCronAuth($request);
        } catch (AccessDeniedException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 401);
        }

        $today = new \DateTimeImmutable('today');
        $sent  = 0;
        $errors = 0;

        foreach (self::DAY_OFFSETS as $dayOffset) {
            $targetDate    = $today->modify(sprintf('%+d days', -$dayOffset));
            $targetStart   = $targetDate->setTime(0, 0, 0);
            $targetEnd     = $targetDate->setTime(23, 59, 59);

            // Load pending (or failed for overdue) schedules due on this date
            // Exclusions (e.g. opted-out profiles) are read from the email_bounces table
            // via EmailService::send() which checks bounce records before sending.
            $schedules = $this->em->createQueryBuilder()
                ->select('ps')
                ->from(PaymentSchedule::class, 'ps')
                ->where('ps.dueAt >= :start')
                ->andWhere('ps.dueAt <= :end')
                ->andWhere('ps.status IN (:statuses)')
                ->setParameter('start', $targetStart)
                ->setParameter('end', $targetEnd)
                ->setParameter('statuses', [ScheduleStatus::Pending->value, ScheduleStatus::Failed->value])
                ->getQuery()
                ->getResult();

            foreach ($schedules as $schedule) {
                $profile = $this->em->getRepository(Profile::class)->find($schedule->getProfileId());

                if ($profile === null) {
                    $this->logger->warning('PaymentScheduleCron: profile not found', [
                        'schedule_id' => $schedule->getId(),
                        'profile_id'  => $schedule->getProfileId(),
                    ]);
                    continue;
                }

                try {
                    $this->emailService->sendPaymentReminder($schedule, $profile, $dayOffset);
                    ++$sent;
                } catch (\Throwable $e) {
                    ++$errors;
                    $this->logger->error('PaymentScheduleCron: failed to send reminder', [
                        'schedule_id' => $schedule->getId(),
                        'day_offset'  => $dayOffset,
                        'error'       => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->logger->info('PaymentScheduleCron: completed', ['sent' => $sent, 'errors' => $errors]);

        return new JsonResponse(['success' => true, 'sent' => $sent, 'errors' => $errors]);
    }
}
