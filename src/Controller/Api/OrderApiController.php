<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Order\OrderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class OrderApiController extends AbstractController
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {
    }

    /**
     * POST /api/v1/orders/create
     * Create a new order. Returns stripeUrl (online) or orderId (onsite).
     */
    #[Route('/api/v1/orders/create', name: 'api_v1_orders_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if ($user === null) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthenticated.'], 401);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        try {
            $result = $this->orderService->createOrder($data, $user);
        } catch (AccessDeniedException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 403);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $payload = ['success' => true];

        if (isset($result['stripeUrl'])) {
            $payload['stripeUrl']      = $result['stripeUrl'];
            $payload['intentOrderId']  = $result['intentOrderId'];
        } else {
            $payload['orderId'] = $result['orderId'];
        }

        return new JsonResponse($payload, 201);
    }

    /**
     * POST /api/v1/orders/update
     * Update an existing order. Requires admin role.
     */
    #[Route('/api/v1/orders/update', name: 'api_v1_orders_update', methods: ['POST'])]
    public function update(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if ($user === null) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthenticated.'], 401);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $orderId = $data['orderId'] ?? null;

        if ($orderId === null) {
            return new JsonResponse(['success' => false, 'error' => 'orderId is required.'], 422);
        }

        try {
            $order = $this->orderService->updateOrder((string) $orderId, $data, $user);
        } catch (AccessDeniedException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 403);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return new JsonResponse([
            'success' => true,
            'orderId' => $order->getId(),
        ]);
    }
}
