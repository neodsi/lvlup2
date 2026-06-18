<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Form\Auth\ResetPasswordRequestType;
use App\Form\Auth\SetPasswordType;
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
        $form = $this->createForm(ResetPasswordRequestType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = $form->get('email')->getData();

            $this->registrationService->requestPasswordReset($email);

            return $this->render('auth/reset_password_confirmation.html.twig', [
                'email' => $email,
            ]);
        }

        return $this->render('auth/reset_password.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/update-password', name: 'app_update_password', methods: ['GET', 'POST'])]
    public function updatePassword(Request $request): Response
    {
        $token = (string) $request->query->get('token', '');

        $form = $this->createForm(SetPasswordType::class, null, ['data' => ['token' => $token]]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $password   = $form->get('password')->getData();
            $resetToken = $form->get('token')->getData() ?? $token;

            if (strlen($password) < 8) {
                $this->addFlash('error', 'Le mot de passe doit contenir au moins 8 caractères.');

                return $this->render('auth/update_password.html.twig', [
                    'token' => $resetToken,
                    'form'  => $form->createView(),
                ]);
            }

            $success = $this->registrationService->resetPassword($resetToken, $password);

            if (!$success) {
                $this->addFlash('error', 'Le lien de réinitialisation est invalide ou a expiré.');

                return $this->render('auth/update_password.html.twig', [
                    'token' => $resetToken,
                    'form'  => $form->createView(),
                ]);
            }

            $this->addFlash('success', 'Votre mot de passe a été mis à jour. Vous pouvez vous connecter.');

            return $this->redirect('/login');
        }

        return $this->render('auth/update_password.html.twig', [
            'token' => $token,
            'form'  => $form->createView(),
        ]);
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
