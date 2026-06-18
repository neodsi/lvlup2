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

        $currentSchoolId = $data['currentSchoolId'] ?? null;

        if ($currentSchoolId !== null) {
            $session = $request->getSession();
            $session->set('currentSchoolId', $currentSchoolId);

            $cookie = Cookie::create('currentSchoolId')
                ->withValue((string) $currentSchoolId)
                ->withExpires(new \DateTimeImmutable('+30 days'))
                ->withPath('/')
                ->withHttpOnly(false)
                ->withSameSite('lax');

            $response = new JsonResponse([
                'success'       => true,
                'currentSchoolId' => $currentSchoolId,
            ]);

            $response->headers->setCookie($cookie);

            return $response;
        }

        // Read mode: return current value from session or cookie
        $schoolId = $request->getSession()->get('currentSchoolId')
                  ?? $request->cookies->get('currentSchoolId');

        return new JsonResponse([
            'success'       => true,
            'currentSchoolId' => $schoolId,
        ]);
    }
}
