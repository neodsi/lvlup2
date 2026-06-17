<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\Profile;
use App\Enum\Gender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ProfileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/profile', name: 'app_profile')]
    #[IsGranted('ROLE_USER')]
    public function profile(): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $profiles = $user->getProfiles()->filter(
            fn (Profile $p) => $p->getDeletedAt() === null
        )->getValues();

        return $this->render('app/profile/index.html.twig', [
            'profiles' => $profiles,
        ]);
    }

    #[Route('/profile/edit', name: 'app_profile_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function editProfile(Request $request): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $primaryProfile = $user->getProfiles()->filter(
            fn (Profile $p) => $p->isPrimary() && $p->getDeletedAt() === null
        )->first();

        if ($primaryProfile === false) {
            return $this->redirectToRoute('app_setup_profile');
        }

        $errors = [];
        $formData = [
            'first_name' => $primaryProfile->getFirstName(),
            'last_name'  => $primaryProfile->getLastName(),
            'dob'        => $primaryProfile->getDob()?->format('Y-m-d') ?? '',
            'gender'     => $primaryProfile->getGender()?->value ?? '',
            'phone'      => $primaryProfile->getPhone() ?? '',
        ];

        if ($request->isMethod('POST')) {
            $formData['first_name'] = trim((string) $request->request->get('first_name', ''));
            $formData['last_name']  = trim((string) $request->request->get('last_name', ''));
            $formData['dob']        = trim((string) $request->request->get('dob', ''));
            $formData['gender']     = trim((string) $request->request->get('gender', ''));
            $formData['phone']      = trim((string) $request->request->get('phone', ''));

            if ($formData['first_name'] === '') {
                $errors['first_name'] = 'First name is required.';
            }
            if ($formData['last_name'] === '') {
                $errors['last_name'] = 'Last name is required.';
            }

            if (empty($errors)) {
                $primaryProfile->setFirstName($formData['first_name']);
                $primaryProfile->setLastName($formData['last_name']);

                if ($formData['dob'] !== '') {
                    $dob = \DateTimeImmutable::createFromFormat('Y-m-d', $formData['dob']);
                    if ($dob !== false) {
                        $primaryProfile->setDob($dob);
                    }
                } else {
                    $primaryProfile->setDob(null);
                }

                if ($formData['gender'] !== '') {
                    $gender = Gender::tryFrom($formData['gender']);
                    $primaryProfile->setGender($gender);
                } else {
                    $primaryProfile->setGender(null);
                }

                $primaryProfile->setPhone($formData['phone'] !== '' ? $formData['phone'] : null);

                $this->em->flush();

                $this->addFlash('success', 'Profile updated successfully.');

                return $this->redirectToRoute('app_profile');
            }
        }

        return $this->render('app/profile/edit.html.twig', [
            'profile'  => $primaryProfile,
            'formData' => $formData,
            'errors'   => $errors,
            'genders'  => Gender::cases(),
        ]);
    }
}
