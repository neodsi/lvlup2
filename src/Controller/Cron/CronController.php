<?php

declare(strict_types=1);

namespace App\Controller\Cron;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

abstract class CronController extends AbstractController
{
    public function __construct(
        protected readonly string $cronSecret,
    ) {
    }

    /**
     * Verify that the request carries a valid "Authorization: Bearer {CRON_SECRET}" header.
     *
     * @throws AccessDeniedException if the token is missing or does not match
     */
    protected function checkCronAuth(Request $request): void
    {
        $authorizationHeader = $request->headers->get('Authorization', '');
        $expected            = 'Bearer ' . $this->cronSecret;

        if (!hash_equals($expected, $authorizationHeader)) {
            throw new AccessDeniedException('Invalid or missing cron authorization token.');
        }
    }
}
