<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Service\Auth\RegistrationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SignupController extends AbstractController
{
    public function __construct(
        private readonly RegistrationService $registrationService,
    ) {}

    #[Route('/signup', name: 'app_signup', methods: ['GET', 'POST'])]
    public function signup(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return $this->render('auth/signup.html.twig');
        }

        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_ANONYMOUSLY');

        $submittedToken = (string) $request->request->get('_csrf_token');
        if (!$this->isCsrfTokenValid('signup', $submittedToken)) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->render('auth/signup.html.twig', [
                'email' => $request->request->get('email', ''),
            ]);
        }

        $email    = trim((string) $request->request->get('email', ''));
        $password = (string) $request->request->get('password', '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Veuillez saisir une adresse e-mail valide.');

            return $this->render('auth/signup.html.twig', ['email' => $email]);
        }

        if (strlen($password) < 8) {
            $this->addFlash('error', 'Le mot de passe doit contenir au moins 8 caractères.');

            return $this->render('auth/signup.html.twig', ['email' => $email]);
        }

        try {
            $this->registrationService->registerUser($email, $password);
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->render('auth/signup.html.twig', ['email' => $email]);
        }

        $this->addFlash('success', 'Votre compte a été créé. Vérifiez votre e-mail pour confirmer votre adresse.');

        return $this->redirect('/setup/profile');
    }
}
