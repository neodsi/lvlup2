<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\Profile;
use App\Entity\User;
use App\Enum\Gender;
use App\Form\App\ProfileEditType;
use App\Service\Email\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints\Email as EmailConstraint;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ProfileController extends AbstractController
{
    private const AVATAR_MAX_BYTES    = 2 * 1024 * 1024; // 2 MB
    private const AVATAR_ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
        private readonly EmailService $emailService,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/profile', name: 'app_profile')]
    #[IsGranted('ROLE_USER')]
    public function profile(): Response
    {
        return $this->redirectToRoute('app_profile_edit');
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

        $initialData = [
            'firstName'  => $primaryProfile->getFirstName(),
            'lastName'   => $primaryProfile->getLastName(),
            'phone'      => $primaryProfile->getPhone() ?? '',
            'dob'        => $primaryProfile->getDob()?->format('Y-m-d') ?? '',
            'gender'     => $primaryProfile->getGender()?->value ?? '',
            'sizeTop'    => $primaryProfile->getSizeTop() ?? '',
            'sizeBottom' => $primaryProfile->getSizeBottom() ?? '',
            'sizeShoe'   => $primaryProfile->getSizeShoe() ?? '',
        ];

        $form = $this->createForm(ProfileEditType::class, $initialData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $primaryProfile->setFirstName($data['firstName']);
            $primaryProfile->setLastName($data['lastName']);

            if ($this->isGranted('ROLE_STUDENT')) {
                $primaryProfile->setSizeTop($data['sizeTop'] !== '' ? $data['sizeTop'] : null);
                $primaryProfile->setSizeBottom($data['sizeBottom'] !== '' ? $data['sizeBottom'] : null);
                $primaryProfile->setSizeShoe($data['sizeShoe'] !== '' ? $data['sizeShoe'] : null);
            }

            $dob = $data['dob'] ?? '';
            if ($dob !== '' && $dob !== null) {
                $dobDate = \DateTimeImmutable::createFromFormat('Y-m-d', $dob);
                if ($dobDate !== false) {
                    $primaryProfile->setDob($dobDate);
                }
            } else {
                $primaryProfile->setDob(null);
            }

            $genderValue = $data['gender'] ?? '';
            if ($genderValue !== '' && $genderValue !== null) {
                $primaryProfile->setGender(Gender::tryFrom($genderValue));
            } else {
                $primaryProfile->setGender(null);
            }

            $phone = $data['phone'] ?? '';
            $primaryProfile->setPhone($phone !== '' ? $phone : null);

            // Avatar upload
            /** @var UploadedFile|null $avatar */
            $avatar = $form->get('avatar')->getData();
            if ($avatar !== null && $avatar->isValid()) {
                $avatarError = $this->processAvatarUpload($avatar, $primaryProfile);
                if ($avatarError !== null) {
                    $this->addFlash('error', $avatarError);

                    return $this->render('app/profile/edit.html.twig', [
                        'profile' => $primaryProfile,
                        'form'    => $form->createView(),
                    ]);
                }
            }

            $this->em->flush();
            $this->addFlash('success', 'Profil mis à jour avec succès.');

            return $this->redirectToRoute('app_profile_edit');
        }

        return $this->render('app/profile/edit.html.twig', [
            'profile' => $primaryProfile,
            'form'    => $form->createView(),
        ]);
    }

    #[Route('/profile/email', name: 'app_profile_email_change', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function requestEmailChange(Request $request): Response
    {
        /** @var User $user */
        $user     = $this->getUser();
        $newEmail = trim((string) $request->request->get('newEmail', ''));

        if ($newEmail === '') {
            $this->addFlash('error', 'Veuillez saisir une adresse e-mail.');
            return $this->redirectToRoute('app_profile_edit');
        }

        $errors = $this->validator->validate($newEmail, new EmailConstraint());
        if (count($errors) > 0) {
            $this->addFlash('error', 'Adresse e-mail invalide.');
            return $this->redirectToRoute('app_profile_edit');
        }

        if ($newEmail === $user->getEmail()) {
            $this->addFlash('error', 'Cette adresse est déjà votre adresse actuelle.');
            return $this->redirectToRoute('app_profile_edit');
        }

        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $newEmail]);
        if ($existing !== null) {
            $this->addFlash('error', 'Cette adresse e-mail est déjà utilisée par un autre compte.');
            return $this->redirectToRoute('app_profile_edit');
        }

        $token = Uuid::v4()->toRfc4122();
        $user->setPendingEmail($newEmail);
        $user->setEmailChangeToken($token);
        $user->setEmailChangeTokenExpiresAt(new \DateTimeImmutable('+48 hours'));
        $this->em->flush();

        try {
            $this->emailService->sendEmailChangeConfirmation($user);
        } catch (\Throwable) {
        }

        $this->addFlash('success', sprintf(
            'Un e-mail de confirmation a été envoyé à %s. Cliquez sur le lien dans cet e-mail pour valider le changement.',
            $newEmail,
        ));

        return $this->redirectToRoute('app_profile_edit');
    }

    #[Route('/profile/email/confirm', name: 'app_profile_email_confirm', methods: ['GET'])]
    public function confirmEmailChange(Request $request): Response
    {
        $token = (string) $request->query->get('token', '');

        $user = $this->em->getRepository(User::class)->findOneBy(['emailChangeToken' => $token]);

        if ($user === null
            || $user->getEmailChangeTokenExpiresAt() === null
            || $user->getEmailChangeTokenExpiresAt() < new \DateTimeImmutable()
            || $user->getPendingEmail() === null
        ) {
            $this->addFlash('error', 'Lien de confirmation invalide ou expiré.');
            return $this->redirectToRoute('app_profile_edit');
        }

        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $user->getPendingEmail()]);
        if ($existing !== null && $existing->getId() !== $user->getId()) {
            $user->setPendingEmail(null);
            $user->setEmailChangeToken(null);
            $user->setEmailChangeTokenExpiresAt(null);
            $this->em->flush();
            $this->addFlash('error', 'Cette adresse e-mail est déjà utilisée par un autre compte.');
            return $this->redirectToRoute('app_profile_edit');
        }

        $user->setEmail((string) $user->getPendingEmail());
        $user->setPendingEmail(null);
        $user->setEmailChangeToken(null);
        $user->setEmailChangeTokenExpiresAt(null);
        $this->em->flush();

        // Refresh the security token so the new email (= user identifier) is used
        $newToken = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $this->tokenStorage->setToken($newToken);
        $request->getSession()->set('_security_main', serialize($newToken));

        $this->addFlash('success', 'Votre adresse e-mail a bien été mise à jour.');

        return $this->redirectToRoute('app_profile_edit');
    }

    private function processAvatarUpload(UploadedFile $file, Profile $profile): ?string
    {
        if ($file->getSize() > self::AVATAR_MAX_BYTES) {
            return 'La photo ne doit pas dépasser 2 Mo.';
        }

        $finfo    = new \finfo(\FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file->getRealPath());

        if (!\in_array($mimeType, self::AVATAR_ALLOWED_MIME, true)) {
            return 'Format non supporté. Utilisez JPG, PNG, WebP ou GIF.';
        }

        $ext      = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => 'jpg',
        };
        $filename = Uuid::v4()->toRfc4122() . '.' . $ext;
        $destDir  = $this->projectDir . '/public/uploads/profiles/' . $profile->getId();

        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            return 'Impossible de créer le répertoire de destination.';
        }

        // Delete old avatar file if it exists
        $oldPath = $profile->getAvatarPath();
        if ($oldPath !== null) {
            $oldFile = $this->projectDir . '/public/' . $oldPath;
            if (is_file($oldFile)) {
                @unlink($oldFile);
            }
        }

        $file->move($destDir, $filename);
        $profile->setAvatarPath('uploads/profiles/' . $profile->getId() . '/' . $filename);

        return null;
    }
}
