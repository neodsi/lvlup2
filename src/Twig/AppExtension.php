<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\TeamProfile;
use App\Enum\TeamRole;
use App\Service\TeamContextService;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly TeamContextService $teamContextService,
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
            new TwigFunction('is_team_admin', $this->isTeamAdmin(...)),
            new TwigFunction('current_team_profile', $this->currentTeamProfile(...)),
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
     * Returns true if the given TeamProfile has role team_admin or team_owner.
     */
    public function isTeamAdmin(TeamProfile $teamProfile): bool
    {
        return in_array($teamProfile->getRole(), [TeamRole::TeamAdmin, TeamRole::TeamOwner], true);
    }

    /**
     * Returns the current user's TeamProfile for the current team (reads from session).
     */
    public function currentTeamProfile(): ?TeamProfile
    {
        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return null;
        }

        $user = $token->getUser();
        if (!$user instanceof \App\Entity\User) {
            return null;
        }

        return $this->teamContextService->getCurrentTeamProfile($user);
    }
}
