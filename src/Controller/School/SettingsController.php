<?php

declare(strict_types=1);

namespace App\Controller\School;

use App\Entity\Room;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamProfileSeason;
use App\Entity\User;
use App\Enum\FeePaidBy;
use App\Security\Voter\SeasonVoter;
use App\Security\Voter\TeamVoter;
use App\Service\Season\SeasonService;
use App\Service\TeamContextService;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\StripeClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

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

    #[Route('', name: 'school_settings', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $team = $this->teamContext->getCurrentTeam();

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::UPDATE, $team);

        $slugger       = new AsciiSlugger();
        $suggestedSlug = strtolower($slugger->slug($team->getName())->toString());

        if ($request->isMethod('POST')) {
            $team->setName((string) $request->request->get('name', $team->getName()));
            $team->setWebsiteUrl($request->request->get('websiteUrl') ?: null);
            $team->setContactEmail($request->request->get('contactEmail') ?: null);
            $team->setPhone($request->request->get('phone') ?: null);
            $team->setDescription($request->request->get('description') ?: null);
            $team->setSchedule($request->request->get('schedule') ?: null);
            $team->setPricing($request->request->get('pricing') ?: null);
            $team->setReadAndCheck($request->request->get('readAndCheck') ?: null);

            $slugRaw = $request->request->get('slug') ?: null;
            if ($slugRaw) {
                $slug = strtolower($slugger->slug($slugRaw)->toString());
                if ($slug !== $team->getCurrentSlug()) {
                    $conflict = $this->em->createQueryBuilder()
                        ->select('COUNT(t.id)')
                        ->from(Team::class, 't')
                        ->where('t.currentSlug = :slug')
                        ->andWhere('t.id != :id')
                        ->setParameter('slug', $slug)
                        ->setParameter('id', $team->getId())
                        ->getQuery()->getSingleScalarResult();

                    if ($conflict > 0) {
                        $this->addFlash('error', 'Cet identifiant public est déjà utilisé par une autre école.');
                        return $this->redirectToRoute('school_settings');
                    }

                    $prev = $team->getPreviousSlugs() ?? [];
                    if ($team->getCurrentSlug()) {
                        $prev[] = $team->getCurrentSlug();
                    }
                    $team->setPreviousSlugs(array_values(array_unique($prev)));
                    $team->setCurrentSlug($slug);
                }
            }

            $uploadDir = $this->getParameter('kernel.project_dir') . '/public/media/teams/' . $team->getId();
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }

            /** @var UploadedFile|null $photo */
            $photo = $request->files->get('photo');
            if ($photo instanceof UploadedFile) {
                $filename = 'photo.' . $photo->guessExtension();
                $photo->move($uploadDir, $filename);
                $team->setAvatarPath('media/teams/' . $team->getId() . '/' . $filename);
            }

            /** @var UploadedFile|null $logo */
            $logo = $request->files->get('logo');
            if ($logo instanceof UploadedFile) {
                $filename = 'logo.' . $logo->guessExtension();
                $logo->move($uploadDir, $filename);
                $team->setLogoPath('media/teams/' . $team->getId() . '/' . $filename);
            }

            $team->setUpdatedAt(new \DateTimeImmutable());
            $this->em->flush();
            $this->addFlash('success', 'Paramètres généraux mis à jour.');

            return $this->redirectToRoute('school_settings');
        }

        return $this->render('school/settings/index.html.twig', [
            'team'          => $team,
            'suggestedSlug' => $suggestedSlug,
        ]);
    }

    #[Route('/legal', name: 'school_settings_legal', methods: ['GET', 'POST'])]
    public function legal(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $team = $this->teamContext->getCurrentTeam();

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::UPDATE, $team);

        if ($request->isMethod('POST')) {
            $team->setCurrency($request->request->get('currency', 'EUR'));
            $team->setCompanyName($request->request->get('companyName') ?: null);
            $team->setSiret($request->request->get('siret') ?: null);
            $team->setIban($request->request->get('iban') ?: null);
            $team->setApeNaf($request->request->get('apeNaf') ?: null);
            $team->setIsCollectingVat((bool) $request->request->get('isCollectingVat'));
            $team->setVatNumber($request->request->get('vatNumber') ?: null);
            $team->setInvoiceAddress($request->request->get('invoiceAddress') ?: null);
            $team->setAddressText($request->request->get('addressText') ?: null);
            $team->setAddressLat($request->request->get('addressLat') ?: null);
            $team->setAddressLng($request->request->get('addressLng') ?: null);

            $feePaidBy = FeePaidBy::tryFrom($request->request->get('feePaidBy', 'student'));
            if ($feePaidBy) {
                $team->setFeePaidBy($feePaidBy);
            }

            $rawMethods = $request->request->all('methods') ?: [];
            $paymentMethods = [];
            foreach ($rawMethods as $key => $dims) {
                $paymentMethods[] = [
                    'id'                          => $key,
                    'allowed_for_one_shot'        => !empty($dims['one_shot']),
                    'allowed_for_multiple_payments' => !empty($dims['multiple']),
                ];
            }
            $team->setPaymentMethods($paymentMethods);

            $team->setUpdatedAt(new \DateTimeImmutable());
            $this->em->flush();
            $this->addFlash('success', 'Informations légales mises à jour.');

            return $this->redirectToRoute('school_settings_legal');
        }

        return $this->render('school/settings/legal.html.twig', ['team' => $team]);
    }

    #[Route('/stripe', name: 'school_settings_stripe', methods: ['GET'])]
    public function stripe(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $team = $this->teamContext->getCurrentTeam();

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::CONFIGURE_STRIPE, $team);

        return $this->render('school/settings/stripe.html.twig', ['team' => $team]);
    }

    #[Route('/stripe/portal', name: 'school_settings_stripe_portal', methods: ['GET'])]
    public function stripePortal(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $team = $this->teamContext->getCurrentTeam();

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::CONFIGURE_STRIPE, $team);

        $accountId = $team->getStripeAccountId();
        if ($accountId === null) {
            $this->addFlash('error', 'Aucun compte Stripe associé à cette école.');
            return $this->redirectToRoute('school_settings_stripe');
        }

        $stripeKey = $this->getParameter('app.stripe_secret_key');
        $stripe    = new StripeClient((string) $stripeKey);

        $returnUrl  = $this->generateUrl('school_settings_stripe', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);
        $refreshUrl = $returnUrl . '?refresh=true';

        // Try login link first (only works for fully onboarded accounts)
        $loginUrl = null;
        try {
            $loginLink = $stripe->accounts->createLoginLink($accountId);
            $loginUrl  = $loginLink->url;
        } catch (\Exception) {
            // Account not yet onboarded — fall through to account link
        }

        // Always generate an onboarding/update account link
        $accountLink = $stripe->accountLinks->create([
            'account'            => $accountId,
            'refresh_url'        => $refreshUrl,
            'return_url'         => $returnUrl,
            'type'               => 'account_onboarding',
            'collection_options' => ['fields' => 'eventually_due'],
        ]);

        // Prefer login link when account is active and has no pending requirements
        $hasRequirements = $team->getStripeAccountStatus()->value !== 'active';
        $redirectUrl     = ($loginUrl && !$hasRequirements) ? $loginUrl : $accountLink->url;

        return $this->redirect($redirectUrl);
    }

    #[Route('/saisons', name: 'school_settings_seasons', methods: ['GET'])]
    public function seasons(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $team = $this->teamContext->getCurrentTeam();

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::UPDATE, $team);

        $seasons = $this->em->getRepository(Season::class)->findBy(
            ['teamId' => $team->getId()],
            ['createdAt' => 'DESC'],
        );

        return $this->render('school/settings/seasons.html.twig', [
            'team'    => $team,
            'seasons' => $seasons,
        ]);
    }

    #[Route('/rooms', name: 'school_settings_rooms', methods: ['GET', 'POST'])]
    public function rooms(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $team = $this->teamContext->getCurrentTeam();

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::UPDATE, $team);

        $seasonId = $team->getCurrentSeasonId();
        $season   = $seasonId ? $this->em->getRepository(Season::class)->find($seasonId) : null;

        if ($season !== null && $request->isMethod('POST')) {
            $method = $request->request->get('_method');

            if ($method === 'DELETE') {
                $roomId = $request->request->get('roomId');
                $room   = $this->em->getRepository(Room::class)->find($roomId);

                if ($room !== null && $room->getTeamId() === $team->getId()) {
                    $this->em->remove($room);
                    $this->em->flush();
                }
            } else {
                $room = new Room();
                $room->setTeamId($team->getId());
                $room->setSeasonId($season->getId());
                $room->setName((string) $request->request->get('name'));
                $this->em->persist($room);
                $this->em->flush();
                $this->addFlash('success', 'Salle ajoutée.');
            }

            return $this->redirectToRoute('school_settings_rooms');
        }

        $rooms = $season
            ? $this->em->getRepository(Room::class)->findBy(['seasonId' => $season->getId()])
            : [];

        return $this->render('school/settings/rooms.html.twig', [
            'team'   => $team,
            'season' => $season,
            'rooms'  => $rooms,
        ]);
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

            $this->addFlash('success', 'Saison créée avec succès.');

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
            $this->addFlash('success', 'Saison mise à jour.');

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
