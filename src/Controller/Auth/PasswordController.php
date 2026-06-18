<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Service\Auth\RegistrationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PasswordController extends AbstractController
{
    public function __construct(
        private readonly RegistrationService $registrationService,
    ) {}

    #[Route('/reset-password', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return $this->render('auth/reset_password.html.twig');
        }

        $submittedToken = (string) $request->request->get('_csrf_token');
        if (!$this->isCsrfTokenValid('reset_password', $submittedToken)) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->render('auth/reset_password.html.twig');
        }

        $email = trim((string) $request->request->get('email', ''));

        $this->registrationService->requestPasswordReset($email);

        return $this->render('auth/reset_password_confirmation.html.twig', [
            'email' => $email,
        ]);
    }

    #[Route('/update-password', name: 'app_update_password', methods: ['GET', 'POST'])]
    public function updatePassword(Request $request): Response
    {
        $token = (string) $request->query->get('token', '');

        if ($request->isMethod('GET')) {
            return $this->render('auth/update_password.html.twig', [
                'token' => $token,
            ]);
        }

        $submittedToken = (string) $request->request->get('_csrf_token');
        if (!$this->isCsrfTokenValid('update_password', $submittedToken)) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->render('auth/update_password.html.twig', ['token' => $token]);
        }

        $resetToken  = (string) $request->request->get('token', $token);
        $newPassword = (string) $request->request->get('password', '');

        if (strlen($newPassword) < 8) {
            $this->addFlash('error', 'Le mot de passe doit contenir au moins 8 caractères.');

            return $this->render('auth/update_password.html.twig', ['token' => $resetToken]);
        }

        $success = $this->registrationService->resetPassword($resetToken, $newPassword);

        if (!$success) {
            $this->addFlash('error', 'Le lien de réinitialisation est invalide ou a expiré.');

            return $this->render('auth/update_password.html.twig', ['token' => $resetToken]);
        }

        $this->addFlash('success', 'Votre mot de passe a été mis à jour. Vous pouvez vous connecter.');

        return $this->redirect('/login');
    }

    #[Route('/auth/confirm-email/{token}', name: 'app_email_confirm_legacy', methods: ['GET'])]
    public function confirmEmailLegacy(string $token): Response
    {
        return $this->redirectToRoute('app_email_confirm', ['token' => $token], 301);
    }

    #[Route('/auth/confirm', name: 'app_email_confirm', methods: ['GET'])]
    public function confirmEmail(Request $request): Response
    {
        $token = (string) $request->query->get('token', '');

        $confirmed = $this->registrationService->confirmEmail($token);

        if (!$confirmed) {
            $this->addFlash('error', 'Le lien de confirmation est invalide ou a déjà été utilisé.');
        } else {
            $this->addFlash('success', 'Votre adresse e-mail a été confirmée.');
        }

        return $this->redirect('/home');
    }
}
