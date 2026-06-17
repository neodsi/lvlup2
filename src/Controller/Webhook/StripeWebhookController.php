<?php

declare(strict_types=1);

namespace App\Controller\Webhook;

use App\Entity\IntentOrder;
use App\Entity\Payment;
use App\Entity\PaymentSchedule;
use App\Enum\ScheduleStatus;
use App\Service\Email\EmailService;
use App\Service\Order\OrderService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrderService $orderService,
        private readonly EmailService $emailService,
        private readonly LoggerInterface $logger,
        private readonly string $stripeWebhookSecret,
    ) {
    }

    /**
     * POST /webhooks/stripe/connect
     * Handle Stripe Connect webhook events.
     * Always returns HTTP 200 (Stripe requirement) – errors are logged and captured to Sentry.
     */
    #[Route('/webhooks/stripe/connect', name: 'webhook_stripe_connect', methods: ['POST'])]
    public function connect(Request $request): Response
    {
        $payload   = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature', '');

        // 1. Verify Stripe signature
        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $this->stripeWebhookSecret);
        } catch (SignatureVerificationException $e) {
            $this->logger->warning('StripeWebhook: invalid signature', ['error' => $e->getMessage()]);

            return new Response('Invalid signature.', Response::HTTP_BAD_REQUEST);
        } catch (\UnexpectedValueException $e) {
            $this->logger->warning('StripeWebhook: invalid payload', ['error' => $e->getMessage()]);

            return new Response('Invalid payload.', Response::HTTP_BAD_REQUEST);
        }

        // 2. Dispatch by event type
        try {
            match ($event->type) {
                'checkout.session.completed',
                'checkout.session.async_payment_succeeded' => $this->handleCheckoutSessionCompleted($event->data->object),
                'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event->data->object),
                default => null, // Ignore unhandled event types
            };
        } catch (\Throwable $e) {
            $this->logger->error('StripeWebhook: unhandled exception while processing event', [
                'event_type' => $event->type,
                'event_id'   => $event->id,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            \Sentry\captureException($e);
        }

        // 3. Always return 200 to Stripe
        return new JsonResponse(['received' => true], Response::HTTP_OK);
    }

    // -------------------------------------------------------------------------
    // Event handlers
    // -------------------------------------------------------------------------

    private function handleCheckoutSessionCompleted(object $session): void
    {
        $sessionId = $session->id ?? null;

        if ($sessionId === null) {
            $this->logger->error('StripeWebhook: checkout.session.completed – missing session id');

            return;
        }

        // Locate the IntentOrder by stripeCheckoutSessionId (set during the Stripe URL creation)
        $intentOrder = $this->em->getRepository(IntentOrder::class)->findOneBy([
            'stripeCheckoutSessionId' => $sessionId,
        ]);

        // The session id is also stored in metadata; fall back to searching by metadata order id
        if ($intentOrder === null) {
            $metaIntentOrderId = $session->metadata->intent_order_id ?? null;

            if ($metaIntentOrderId !== null) {
                $intentOrder = $this->em->getRepository(IntentOrder::class)->find($metaIntentOrderId);
            }
        }

        if ($intentOrder === null) {
            $this->logger->error('StripeWebhook: checkout.session.completed – IntentOrder not found', [
                'stripe_session_id' => $sessionId,
            ]);

            return;
        }

        try {
            $this->orderService->fulfillFromIntent($intentOrder->getId(), $sessionId);
        } catch (\Throwable $e) {
            $this->logger->error('StripeWebhook: fulfillFromIntent failed', [
                'intent_order_id'   => $intentOrder->getId(),
                'stripe_session_id' => $sessionId,
                'error'             => $e->getMessage(),
            ]);

            \Sentry\captureException($e);

            // Re-throw so the outer catch can still log + return 200
            throw $e;
        }
    }

    private function handlePaymentIntentSucceeded(object $paymentIntent): void
    {
        $intentId = $paymentIntent->id ?? null;

        if ($intentId === null) {
            $this->logger->error('StripeWebhook: payment_intent.succeeded – missing payment intent id');

            return;
        }

        /** @var Payment|null $payment */
        $payment = $this->em->getRepository(Payment::class)->findOneBy([
            'stripePaymentIntentId' => $intentId,
        ]);

        if ($payment === null) {
            $this->logger->warning('StripeWebhook: payment_intent.succeeded – Payment not found', [
                'stripe_payment_intent_id' => $intentId,
            ]);

            return;
        }

        // Mark payment as paid
        if ($payment->getPaidAt() === null) {
            $payment->setPaidAt(new \DateTimeImmutable());
            $this->em->persist($payment);
        }

        // Update linked PaymentSchedule(s) to paid
        $schedules = $this->em->getRepository(PaymentSchedule::class)->findBy([
            'paymentId' => $payment->getId(),
        ]);

        foreach ($schedules as $schedule) {
            $schedule->setStatus(ScheduleStatus::Paid);
            $this->em->persist($schedule);
        }

        $this->em->flush();
    }
}
