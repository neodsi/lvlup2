<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Profile;
use App\Entity\SchoolProfile;
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
     * Returns true if the given SchoolProfile has role admin or owner.
     */
    public function isSchoolAdmin(SchoolProfile $schoolProfile): bool
    {
        return in_array($schoolProfile->getRole(), [SchoolRole::School, SchoolRole::School], true);
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

        foreach ($user->getProfiles() as $profile) {
            if ($profile->isPrimary() && $profile->getDeletedAt() === null) {
                return $profile;
            }
        }

        return null;
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

        $roles = [];
        foreach ($user->getProfiles() as $profile) {
            if ($profile->getDeletedAt() !== null) {
                continue;
            }
            foreach ($profile->getSchoolProfiles() as $sp) {
                if ($sp->getDeletedAt() !== null) {
                    continue;
                }
                $roles[$sp->getRole()->value] = true;
            }
        }

        return array_keys($roles);
    }

    /**
     * Returns the current user's SchoolProfile for the current school (reads from session).
     */
    public function currentSchoolProfile(): ?SchoolProfile
    {
        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return null;
        }

        $user = $token->getUser();
        if (!$user instanceof \App\Entity\User) {
            return null;
        }

        return $this->schoolContextService->getCurrentSchoolProfile($user);
    }
}
