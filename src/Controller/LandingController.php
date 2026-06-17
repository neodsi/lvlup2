<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LandingController extends AbstractController
{
    #[Route('/', name: 'app_landing')]
    public function index(): Response
    {
        if ($this->isGranted('ROLE_USER')) {
            return $this->redirectToRoute('app_home');
        }

        return $this->render('landing.html.twig');
    }
}
