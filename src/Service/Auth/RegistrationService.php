<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Service\Email\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

class RegistrationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EmailService $emailService,
    ) {}

    /**
     * Register a new user.
     *
     * @throws \RuntimeException if the email is already taken
     */
    public function registerUser(string $email, string $password): User
    {
        $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($existing !== null) {
            throw new \RuntimeException(sprintf('L\'adresse e-mail "%s" est déjà utilisée.', $email));
        }

        $user = new User();
        $user->setEmail($email);

        $hashed = $this->passwordHasher->hashPassword($user, $password);
        $user->setPasswordHash($hashed);

        $user->setEmailVerified(false);

        $confirmationToken = Uuid::v4()->toRfc4122();
        $user->setResetToken($confirmationToken);
        // No expiry for email confirmation tokens – they are valid until used.

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->emailService->sendEmailConfirmation($user, $confirmationToken);

        return $user;
    }

    /**
     * Confirm the user's e-mail address using the token sent by e-mail.
     *
     * Reuses the reset_token field as an email-confirmation token.
     */
    public function confirmEmail(string $token): bool
    {
        /** @var User|null $user */
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['resetToken' => $token]);

        if ($user === null) {
            return false;
        }

        $user->setEmailVerified(true);
        $user->setResetToken(null);
        $user->setResetTokenExpiresAt(null);

        $this->entityManager->flush();

        return true;
    }

    /**
     * Initiate a password-reset flow: generate a token, persist it and send the reset e-mail.
     */
    public function requestPasswordReset(string $email): void
    {
        /** @var User|null $user */
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($user === null) {
            // Do not reveal whether the address exists.
            return;
        }

        $token   = Uuid::v4()->toRfc4122();
        $expires = new \DateTimeImmutable('+1 hour');

        $user->setResetToken($token);
        $user->setResetTokenExpiresAt($expires);

        $this->entityManager->flush();

        $this->emailService->sendPasswordReset($user);
    }

    /**
     * Apply a new password using a valid (non-expired) reset token.
     */
    public function resetPassword(string $token, string $newPassword): bool
    {
        /** @var User|null $user */
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['resetToken' => $token]);

        if ($user === null) {
            return false;
        }

        $expires = $user->getResetTokenExpiresAt();

        if ($expires === null || $expires < new \DateTimeImmutable()) {
            return false;
        }

        $hashed = $this->passwordHasher->hashPassword($user, $newPassword);
        $user->setPasswordHash($hashed);
        $user->setResetToken(null);
        $user->setResetTokenExpiresAt(null);

        $this->entityManager->flush();

        return true;
    }
}
