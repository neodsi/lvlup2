<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\Profile;
use App\Enum\Gender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

class ProfileController extends AbstractController
{
    private const AVATAR_MAX_BYTES    = 2 * 1024 * 1024; // 2 MB
    private const AVATAR_ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
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
                $errors['first_name'] = 'Le prénom est obligatoire.';
            }
            if ($formData['last_name'] === '') {
                $errors['last_name'] = 'Le nom est obligatoire.';
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

                // Avatar upload
                /** @var UploadedFile|null $avatar */
                $avatar = $request->files->get('avatar');
                if ($avatar !== null && $avatar->isValid()) {
                    $avatarError = $this->processAvatarUpload($avatar, $primaryProfile);
                    if ($avatarError !== null) {
                        $errors['avatar'] = $avatarError;
                    }
                }

                if (empty($errors)) {
                    $this->em->flush();
                    $this->addFlash('success', 'Profil mis à jour avec succès.');

                    return $this->redirectToRoute('app_profile');
                }
            }
        }

        return $this->render('app/profile/edit.html.twig', [
            'profile'  => $primaryProfile,
            'formData' => $formData,
            'errors'   => $errors,
            'genders'  => Gender::cases(),
        ]);
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
