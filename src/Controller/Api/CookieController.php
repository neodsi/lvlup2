<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class CookieController extends AbstractController
{
    #[Route('/api/v1/cookies', name: 'api_v1_cookies', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $currentTeamId = $data['currentTeamId'] ?? null;

        if ($currentTeamId !== null) {
            $session = $request->getSession();
            $session->set('currentTeamId', $currentTeamId);

            $cookie = Cookie::create('currentTeamId')
                ->withValue((string) $currentTeamId)
                ->withExpires(new \DateTimeImmutable('+30 days'))
                ->withPath('/')
                ->withHttpOnly(false)
                ->withSameSite('lax');

            $response = new JsonResponse([
                'success'       => true,
                'currentTeamId' => $currentTeamId,
            ]);

            $response->headers->setCookie($cookie);

            return $response;
        }

        // Read mode: return current value from session or cookie
        $teamId = $request->getSession()->get('currentTeamId')
                  ?? $request->cookies->get('currentTeamId');

        return new JsonResponse([
            'success'       => true,
            'currentTeamId' => $teamId,
        ]);
    }
}
