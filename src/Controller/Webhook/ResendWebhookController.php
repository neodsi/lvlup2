<?php

declare(strict_types=1);

namespace App\Controller\Webhook;

use App\Entity\EmailBounce;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ResendWebhookController extends AbstractController
{
    private const HANDLED_EVENTS = ['email.sent', 'email.bounced', 'email.delivery_delayed'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $resendWebhookSecret,
    ) {
    }

    /**
     * POST /webhooks/resend
     * Receive Resend webhook events, verify the signature, and record bounces/deliveries.
     */
    #[Route('/webhooks/resend', name: 'webhook_resend', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $rawPayload = $request->getContent();

        // Verify Resend webhook signature (HMAC-SHA256)
        if (!$this->verifySignature($request, $rawPayload)) {
            $this->logger->warning('ResendWebhook: invalid signature');

            return new Response('Invalid signature.', Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($rawPayload, true);

        if (!is_array($data)) {
            return new Response('Invalid JSON payload.', Response::HTTP_BAD_REQUEST);
        }

        $eventType = $data['type'] ?? null;

        if (!\in_array($eventType, self::HANDLED_EVENTS, true)) {
            // Acknowledge but ignore unknown event types
            return new JsonResponse(['received' => true]);
        }

        try {
            $this->recordEvent($eventType, $data);
        } catch (\Throwable $e) {
            $this->logger->error('ResendWebhook: failed to record event', [
                'event_type' => $eventType,
                'error'      => $e->getMessage(),
            ]);

            \Sentry\captureException($e);
        }

        return new JsonResponse(['received' => true]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function verifySignature(Request $request, string $rawPayload): bool
    {
        if ($this->resendWebhookSecret === '' || $this->resendWebhookSecret === 'change_me') {
            // No secret configured — skip verification (dev/test only)
            return true;
        }

        // Resend uses svix-style headers: svix-id, svix-timestamp, svix-signature
        $svixId        = $request->headers->get('svix-id', '');
        $svixTimestamp = $request->headers->get('svix-timestamp', '');
        $svixSignature = $request->headers->get('svix-signature', '');

        if ($svixId === '' || $svixTimestamp === '' || $svixSignature === '') {
            return false;
        }

        $toSign   = sprintf('%s.%s.%s', $svixId, $svixTimestamp, $rawPayload);
        $secret   = base64_decode(ltrim($this->resendWebhookSecret, 'whsec_'));
        $computed = 'v1,' . base64_encode(hash_hmac('sha256', $toSign, $secret, true));

        // svix-signature may be a space-separated list of "v1,<sig>" values
        $signatures = explode(' ', $svixSignature);

        foreach ($signatures as $sig) {
            if (hash_equals($computed, trim($sig))) {
                return true;
            }
        }

        return false;
    }

    private function recordEvent(string $eventType, array $data): void
    {
        $emailAddress = $data['data']['email'] ?? $data['data']['to'] ?? null;

        if ($emailAddress === null) {
            $this->logger->warning('ResendWebhook: could not extract email address from payload', [
                'event_type' => $eventType,
            ]);

            return;
        }

        // For bounced / delayed events, record in email_bounces table
        if (\in_array($eventType, ['email.bounced', 'email.delivery_delayed'], true)) {
            // Avoid duplicate bounce records for the same address + event type
            $existing = $this->em->getRepository(EmailBounce::class)->findOneBy([
                'email'     => $emailAddress,
                'eventType' => $eventType,
            ]);

            if ($existing === null) {
                $bounce = new EmailBounce();
                $bounce->setEmail($emailAddress);
                $bounce->setEventType($eventType);
                $bounce->setPayload($data);

                $this->em->persist($bounce);
                $this->em->flush();
            }
        }

        // For email.sent, just log the delivery
        if ($eventType === 'email.sent') {
            $this->logger->info('ResendWebhook: email delivered', [
                'email'      => $emailAddress,
                'event_type' => $eventType,
            ]);
        }
    }
}
