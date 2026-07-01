<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Season;
use App\Service\SchoolContextService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class SchoolSidebarExtension extends AbstractExtension
{
    public function __construct(
        private readonly SchoolContextService $schoolContext,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('school_sidebar_data', [$this, 'getSidebarData']),
        ];
    }

    public function getSidebarData(): array
    {
        if (!$this->security->isGranted('ROLE_SCHOOL')) {
            return ['seasons' => [], 'currentSeasonId' => null, 'schoolId' => null];
        }

        $school = $this->schoolContext->getCurrentSchool();
        if ($school === null) {
            return ['seasons' => [], 'currentSeasonId' => null, 'schoolId' => null];
        }

        $seasons = $this->em->getRepository(Season::class)->findBy(
            ['schoolId' => $school->getId()],
            ['createdAt' => 'DESC'],
        );

        return [
            'seasons'         => $seasons,
            'currentSeasonId' => $school->getCurrentSeasonId(),
            'schoolId'        => $school->getId(),
        ];
    }
}
