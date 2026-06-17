<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Security\AppAuthenticator;
use App\Service\Auth\RegistrationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;

class SignupController extends AbstractController
{
    public function __construct(
        private readonly RegistrationService $registrationService,
        private readonly UserAuthenticatorInterface $userAuthenticator,
        private readonly AppAuthenticator $appAuthenticator,
    ) {}

    #[Route('/signup', name: 'app_signup', methods: ['GET', 'POST'])]
    public function signup(Request $request): Response
    {
        if ($this->isGranted('ROLE_USER')) {
            return $this->redirectToRoute('app_home');
        }

        if ($request->isMethod('GET')) {
            return $this->render('auth/signup.html.twig');
        }

        $submittedToken = (string) $request->request->get('_csrf_token');
        if (!$this->isCsrfTokenValid('signup', $submittedToken)) {
            $this->addFlash('error', 'Token CSRF invalide. Veuillez réessayer.');

            return $this->render('auth/signup.html.twig', [
                'email' => $request->request->get('email', ''),
            ]);
        }

        $email           = trim((string) $request->request->get('email', ''));
        $password        = (string) $request->request->get('password', '');
        $passwordConfirm = (string) $request->request->get('password_confirm', '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Veuillez saisir une adresse e-mail valide.');

            return $this->render('auth/signup.html.twig', ['email' => $email]);
        }

        if (strlen($password) < 8) {
            $this->addFlash('error', 'Le mot de passe doit contenir au moins 8 caractères.');

            return $this->render('auth/signup.html.twig', ['email' => $email]);
        }

        if ($password !== $passwordConfirm) {
            $this->addFlash('error', 'Les mots de passe ne correspondent pas.');

            return $this->render('auth/signup.html.twig', ['email' => $email]);
        }

        try {
            $user = $this->registrationService->registerUser($email, $password);
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->render('auth/signup.html.twig', ['email' => $email]);
        }

        $this->addFlash('success', 'Bienvenue ! Vérifiez votre e-mail pour confirmer votre adresse.');

        // Auto-login after registration
        return $this->userAuthenticator->authenticateUser(
            $user,
            $this->appAuthenticator,
            $request,
        ) ?? $this->redirectToRoute('app_setup_profile');
    }
}
