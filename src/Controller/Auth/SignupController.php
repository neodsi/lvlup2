<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Form\Auth\SignupType;
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

        $form = $this->createForm(SignupType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email    = $form->get('email')->getData();
            $password = $form->get('password')->getData();

            try {
                $user = $this->registrationService->registerUser($email, $password);
            } catch (\RuntimeException $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->render('auth/signup.html.twig', [
                    'form' => $form->createView(),
                ]);
            }

            $this->addFlash('success', 'Bienvenue ! Vérifiez votre e-mail pour confirmer votre adresse.');

            // Auto-login after registration
            return $this->userAuthenticator->authenticateUser(
                $user,
                $this->appAuthenticator,
                $request,
            ) ?? $this->redirectToRoute('app_setup_profile');
        }

        return $this->render('auth/signup.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
