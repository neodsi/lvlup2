<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Entity\EmailBounce;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\PaymentSchedule;
use App\Entity\Profile;
use App\Entity\Team;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class EmailService
{
    private const FROM = 'noreply@lvlup.com';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        private readonly MailerInterface $mailer,
    ) {}

    // -------------------------------------------------------------------------
    // Core send methods
    // -------------------------------------------------------------------------

    /**
     * @throws \RuntimeException on send failure
     */
    public function send(string $to, string $subject, string $htmlBody): void
    {
        $bounce = $this->entityManager->getRepository(EmailBounce::class)->findOneBy(['email' => $to]);

        if ($bounce !== null) {
            $this->logger->warning('EmailService: skipping send to bounced address', [
                'to'         => $to,
                'subject'    => $subject,
                'event_type' => $bounce->getEventType(),
                'bounced_at' => $bounce->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ]);

            return;
        }

        try {
            $email = (new Email())
                ->from(self::FROM)
                ->to($to)
                ->subject($subject)
                ->html($htmlBody);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('EmailService: failed to send e-mail', [
                'to'        => $to,
                'subject'   => $subject,
                'exception' => $e->getMessage(),
            ]);

            \Sentry\captureException($e);

            throw new \RuntimeException(
                sprintf('Impossible d\'envoyer l\'e-mail à "%s": %s', $to, $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * Render a Twig template and send it.
     *
     * @param array<string, mixed> $context
     *
     * @throws \RuntimeException on render or send failure
     */
    public function sendTemplated(string $to, string $subject, string $template, array $context = []): void
    {
        $html = $this->twig->render(sprintf('email/%s.html.twig', $template), $context);
        $this->send($to, $subject, $html);
    }

    // -------------------------------------------------------------------------
    // Typed helpers
    // -------------------------------------------------------------------------

    public function sendEmailConfirmation(User $user, string $confirmationToken): void
    {
        $this->sendTemplated(
            $user->getEmail(),
            'Confirmez votre adresse e-mail – LVL UP',
            'confirm',
            [
                'user'               => $user,
                'confirmation_token' => $confirmationToken,
            ],
        );
    }

    public function sendPasswordReset(User $user): void
    {
        $this->sendTemplated(
            $user->getEmail(),
            'Réinitialisation de votre mot de passe – LVL UP',
            'reset_password',
            [
                'user'        => $user,
                'reset_token' => $user->getResetToken(),
                'expires_at'  => $user->getResetTokenExpiresAt(),
            ],
        );
    }

    public function sendInvitation(string $to, Team $team, string $inviteToken): void
    {
        $this->sendTemplated(
            $to,
            sprintf('Vous avez été invité(e) à rejoindre %s – LVL UP', $team->getName()),
            'invitation',
            [
                'team'         => $team,
                'invite_token' => $inviteToken,
                'to'           => $to,
            ],
        );
    }

    public function sendOrderConfirmation(Order $order, Profile $profile): void
    {
        $email = $profile->getUser()?->getEmail();

        if ($email === null) {
            $this->logger->warning('EmailService: cannot send order confirmation – profile has no linked user', [
                'order_id'   => $order->getId(),
                'profile_id' => $profile->getId(),
            ]);

            return;
        }

        $this->sendTemplated(
            $email,
            'Confirmation de votre commande – LVL UP',
            'order_confirmation',
            [
                'order'   => $order,
                'profile' => $profile,
            ],
        );
    }

    public function sendPaymentReminder(PaymentSchedule $schedule, Profile $profile, int $dayOffset): void
    {
        $email = $profile->getUser()?->getEmail();

        if ($email === null) {
            $this->logger->warning('EmailService: cannot send payment reminder – profile has no linked user', [
                'schedule_id' => $schedule->getId(),
                'profile_id'  => $profile->getId(),
            ]);

            return;
        }

        $subject = match (true) {
            $dayOffset < 0  => sprintf('Rappel : votre paiement est dû dans %d jour(s) – LVL UP', abs($dayOffset)),
            $dayOffset === 0 => 'Votre paiement est dû aujourd\'hui – LVL UP',
            default          => sprintf('Votre paiement est en retard de %d jour(s) – LVL UP', $dayOffset),
        };

        $this->sendTemplated(
            $email,
            $subject,
            'payment_reminder',
            [
                'schedule'   => $schedule,
                'profile'    => $profile,
                'day_offset' => $dayOffset,
            ],
        );
    }

    public function sendAutoPayConfirmation(Payment $payment, Profile $profile): void
    {
        $email = $profile->getUser()?->getEmail();

        if ($email === null) {
            $this->logger->warning('EmailService: cannot send auto-pay confirmation – profile has no linked user', [
                'payment_id' => $payment->getId(),
                'profile_id' => $profile->getId(),
            ]);

            return;
        }

        $this->sendTemplated(
            $email,
            'Votre paiement automatique a été effectué – LVL UP',
            'autopay_confirmation',
            [
                'payment' => $payment,
                'profile' => $profile,
            ],
        );
    }
}
