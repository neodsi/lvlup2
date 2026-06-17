<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Enum\AppRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class AdminApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * POST /api/v1/admin/magic-link
     * Generate an impersonation URL. Requires app_super_admin.
     */
    #[Route('/api/v1/admin/magic-link', name: 'api_v1_admin_magic_link', methods: ['POST'])]
    public function magicLink(Request $request): JsonResponse
    {
        /** @var User|null $currentUser */
        $currentUser = $this->getUser();

        if ($currentUser === null) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthenticated.'], 401);
        }

        if (!($currentUser instanceof User) || $currentUser->getAppRole() !== AppRole::AppAdmin) {
            return new JsonResponse(['success' => false, 'error' => 'app_admin role required.'], 403);
        }

        $data   = json_decode($request->getContent(), true) ?? [];
        $userId = $data['userId'] ?? null;
        $email  = $data['email'] ?? null;

        $targetUser = null;

        if ($userId !== null) {
            $targetUser = $this->em->getRepository(User::class)->find($userId);
        } elseif ($email !== null) {
            $targetUser = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        }

        if ($targetUser === null) {
            return new JsonResponse(['success' => false, 'error' => 'Target user not found.'], 404);
        }

        $impersonateUrl = $this->urlGenerator->generate('app_home', [
            '_switch_user' => $targetUser->getEmail(),
        ]);

        return new JsonResponse([
            'success' => true,
            'url'     => $impersonateUrl,
            'userId'  => $targetUser->getId(),
            'email'   => $targetUser->getEmail(),
        ]);
    }
}
