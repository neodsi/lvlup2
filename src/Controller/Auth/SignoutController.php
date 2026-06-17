<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

class SignoutController extends AbstractController
{
    /**
     * The actual logout is handled by Symfony Security (security.yaml logout path).
     * This action will never be reached; it exists only to provide a named route.
     */
    #[Route('/signout', name: 'app_signout')]
    public function signout(): never
    {
        throw new \LogicException('This action should never be reached — intercepted by the security firewall.');
    }
}
