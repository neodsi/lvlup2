<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Team;
use App\Entity\TeamProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class TeamContextService
{
    private const SESSION_KEY = 'currentTeamId';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function getCurrentTeamId(): ?string
    {
        $session = $this->requestStack->getSession();

        /** @var string|null $teamId */
        $teamId = $session->get(self::SESSION_KEY);

        return $teamId ?: null;
    }

    public function setCurrentTeamId(string $teamId, Request $request): void
    {
        $request->getSession()->set(self::SESSION_KEY, $teamId);
    }

    /**
     * Returns the TeamProfile for the given User in the current team context.
     * Loads from DB using the currentTeamId stored in the session.
     */
    public function getCurrentTeamProfile(User $user): ?TeamProfile
    {
        $teamId = $this->getCurrentTeamId();

        if ($teamId === null) {
            return null;
        }

        $profiles = $user->getProfiles();

        if ($profiles->isEmpty()) {
            return null;
        }

        $profileIds = $profiles->map(fn ($p) => $p->getId())->toArray();

        /** @var TeamProfile|null */
        return $this->em->createQueryBuilder()
            ->select('tp')
            ->from(TeamProfile::class, 'tp')
            ->join('tp.profile', 'p')
            ->where('tp.team = :teamId')
            ->andWhere('p.id IN (:profileIds)')
            ->andWhere('tp.deletedAt IS NULL')
            ->setParameter('teamId', $teamId)
            ->setParameter('profileIds', $profileIds)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Returns the current Team entity from the session context.
     */
    public function getCurrentTeam(): ?Team
    {
        $teamId = $this->getCurrentTeamId();

        if ($teamId === null) {
            return null;
        }

        return $this->em->getRepository(Team::class)->find($teamId);
    }
}
