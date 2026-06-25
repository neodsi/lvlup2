<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Profile;
use App\Entity\SchoolUser;
use App\Enum\SchoolRole;
use App\Service\SchoolContextService;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly SchoolContextService $schoolContextService,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }


    public function getFilters(): array
    {
        return [
            new TwigFilter('app_date', $this->formatAppDate(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('is_school_admin', $this->isSchoolAdmin(...)),
            new TwigFunction('current_school_profile', $this->currentSchoolProfile(...)),
            new TwigFunction('primary_profile', $this->primaryProfile(...)),
            new TwigFunction('user_school_roles', $this->userSchoolRoles(...)),
        ];
    }

    /**
     * Formats a DateTimeImmutable to 'd MMM yyyy' in French.
     * Example: new \DateTimeImmutable('2024-03-15') → "15 mars 2024"
     */
    public function formatAppDate(\DateTimeImmutable $date): string
    {
        $formatter = new \IntlDateFormatter(
            'fr_FR',
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            null,
            null,
            'd MMM yyyy',
        );

        return $formatter->format($date);
    }

    /**
     * Returns true if the given SchoolUser has role admin or owner.
     */
    public function isSchoolAdmin(SchoolUser $schoolUser): bool
    {
        return in_array($schoolUser->getRole(), [SchoolRole::School, SchoolRole::School], true);
    }

    /**
     * Returns the primary Profile of the current authenticated user.
     */
    public function primaryProfile(): ?Profile
    {
        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return null;
        }

        $user = $token->getUser();
        if (!$user instanceof \App\Entity\User) {
            return null;
        }

        return $user->getProfile();
    }

    /**
     * Returns the list of SchoolRole values (strings) the current user holds across all schools.
     * e.g. ['student', 'teacher'] — used to conditionally show menu sections.
     *
     * @return string[]
     */
    public function userSchoolRoles(): array
    {
        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return [];
        }

        $user = $token->getUser();
        if (!$user instanceof \App\Entity\User) {
            return [];
        }

        $mapping = [
            'ROLE_SCHOOL'   => 'school',
            'ROLE_TEACHER'  => 'teacher',
            'ROLE_STUDENT'  => 'student',
        ];

        $roles = [];
        foreach ($user->getRoles() as $role) {
            if (isset($mapping[$role])) {
                $roles[] = $mapping[$role];
            }
        }

        return $roles;
    }

    /**
     * Returns the current user's SchoolUser for the current school (reads from session).
     */
    public function currentSchoolProfile(): ?SchoolUser
    {
        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return null;
        }

        $user = $token->getUser();
        if (!$user instanceof \App\Entity\User) {
            return null;
        }

        return $this->schoolContextService->getCurrentSchoolUser($user);
    }
}
