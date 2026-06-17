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
use Sentry\SentryBundle\SentryBundle;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Environment;

class EmailService
{
    private const FROM    = 'noreply@lvlup.com';
    private const API_URL = 'https://api.resend.com/emails';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
        private readonly string $resendApiKey,
    ) {}

    // -------------------------------------------------------------------------
    // Core send methods
    // -------------------------------------------------------------------------

    /**
     * Send a raw HTML e-mail via the Resend HTTP API.
     *
     * Before sending, the address is checked against the email_bounces table.
     * On failure the error is logged and captured to Sentry – an exception is
     * then re-thrown so callers are aware of the failure (never silent drop).
     *
     * @throws \RuntimeException on API or HTTP error
     */
    public function send(string $to, string $subject, string $htmlBody): void
    {
        // Bounce check
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
            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->resendApiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'from'    => self::FROM,
                    'to'      => [$to],
                    'subject' => $subject,
                    'html'    => $htmlBody,
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                $body = $response->getContent(false);
                throw new \RuntimeException(sprintf(
                    'Resend API returned HTTP %d: %s',
                    $statusCode,
                    $body,
                ));
            }
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
            'Confirmez votre adresse e-mail – LvlUp',
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
            'Réinitialisation de votre mot de passe – LvlUp',
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
            sprintf('Vous avez été invité(e) à rejoindre %s – LvlUp', $team->getName()),
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
            'Confirmation de votre commande – LvlUp',
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
            $dayOffset < 0  => sprintf('Rappel : votre paiement est dû dans %d jour(s) – LvlUp', abs($dayOffset)),
            $dayOffset === 0 => 'Votre paiement est dû aujourd\'hui – LvlUp',
            default          => sprintf('Votre paiement est en retard de %d jour(s) – LvlUp', $dayOffset),
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
            'Votre paiement automatique a été effectué – LvlUp',
            'autopay_confirmation',
            [
                'payment' => $payment,
                'profile' => $profile,
            ],
        );
    }
}
