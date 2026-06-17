<?php

declare(strict_types=1);

namespace App\Controller\School;

use App\Entity\Season;
use App\Entity\TeamProfileSeason;
use App\Entity\User;
use App\Security\Voter\SeasonVoter;
use App\Security\Voter\TeamVoter;
use App\Service\Season\SeasonService;
use App\Service\TeamContextService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/school/settings')]
#[IsGranted('ROLE_USER')]
final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly TeamContextService $teamContext,
        private readonly EntityManagerInterface $em,
        private readonly SeasonService $seasonService,
    ) {
    }

    #[Route('', name: 'school_settings', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $team = $this->teamContext->getCurrentTeam();

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::UPDATE, $team);

        return $this->render('school/settings/index.html.twig', ['team' => $team]);
    }

    #[Route('/stripe/payments', name: 'school_settings_stripe_payments', methods: ['GET'])]
    public function stripePayments(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $team = $this->teamContext->getCurrentTeam();

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::CONFIGURE_STRIPE, $team);

        // Stripe checkout sessions are fetched directly via the Stripe SDK.
        // Delegate listing to the service when that method is available,
        // or pass an empty array so the template can handle the not-yet-configured state.
        $sessions = [];

        return $this->render('school/settings/stripe_payments.html.twig', [
            'team'     => $team,
            'sessions' => $sessions,
        ]);
    }

    #[Route('/season', name: 'school_settings_season_current', methods: ['GET'])]
    public function seasonCurrent(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $team = $this->teamContext->getCurrentTeam();

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::UPDATE, $team);

        $seasonId = $team->getCurrentSeasonId();

        if ($seasonId === null) {
            return $this->redirectToRoute('school_settings_season_create');
        }

        return $this->redirectToRoute('school_settings_season', ['id' => $seasonId]);
    }

    #[Route('/season/create', name: 'school_settings_season_create', methods: ['GET', 'POST'])]
    public function seasonCreate(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $team = $this->teamContext->getCurrentTeam();

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::UPDATE, $team);

        if ($request->isMethod('POST')) {
            $season = $this->seasonService->createSeason($team, [
                'name'    => $request->request->get('name'),
                'startAt' => new \DateTimeImmutable((string) $request->request->get('startAt')),
                'endAt'   => new \DateTimeImmutable((string) $request->request->get('endAt')),
            ]);

            $this->addFlash('success', 'Season created.');

            return $this->redirectToRoute('school_settings_season', ['id' => $season->getId()]);
        }

        return $this->render('school/settings/season_create.html.twig', ['team' => $team]);
    }

    #[Route('/season/{id}', name: 'school_settings_season', methods: ['GET', 'POST'])]
    public function season(string $id, Request $request): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $team   = $this->teamContext->getCurrentTeam();
        $season = $this->em->getRepository(Season::class)->find($id);

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        if ($season === null || $season->getTeamId() !== $team->getId()) {
            throw $this->createNotFoundException('Season not found.');
        }

        $this->denyAccessUnlessGranted(SeasonVoter::UPDATE, $season);

        if ($request->isMethod('POST')) {
            $season->setName((string) $request->request->get('name', $season->getName()));

            if ($request->request->get('startAt')) {
                $season->setStartAt(new \DateTimeImmutable((string) $request->request->get('startAt')));
            }
            if ($request->request->get('endAt')) {
                $season->setEndAt(new \DateTimeImmutable((string) $request->request->get('endAt')));
            }
            if ($request->request->get('closures') !== null) {
                $season->setClosures(json_decode((string) $request->request->get('closures'), true));
            }
            if ($request->request->get('registrationFeeId') !== null) {
                $season->setRegistrationFeeId($request->request->get('registrationFeeId') ?: null);
            }

            $this->em->flush();
            $this->addFlash('success', 'Season updated.');

            return $this->redirectToRoute('school_settings_season', ['id' => $id]);
        }

        return $this->render('school/settings/season.html.twig', [
            'team'   => $team,
            'season' => $season,
        ]);
    }

    #[Route('/season/{id}/stats', name: 'school_settings_season_stats', methods: ['GET'])]
    public function seasonStats(string $id): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $team   = $this->teamContext->getCurrentTeam();
        $season = $this->em->getRepository(Season::class)->find($id);

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        if ($season === null || $season->getTeamId() !== $team->getId()) {
            throw $this->createNotFoundException('Season not found.');
        }

        $this->denyAccessUnlessGranted(SeasonVoter::VIEW, $season);

        $registrations = $this->em->getRepository(TeamProfileSeason::class)->findBy([
            'seasonId' => $season->getId(),
            'teamId'   => $team->getId(),
        ]);

        return $this->render('school/settings/season_stats.html.twig', [
            'team'          => $team,
            'season'        => $season,
            'registrations' => $registrations,
        ]);
    }
}
