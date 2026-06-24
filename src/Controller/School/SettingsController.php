<?php

declare(strict_types=1);

namespace App\Controller\School;

use App\Entity\Room;
use App\Entity\Season;
use App\Entity\School;
use App\Entity\SchoolProfileSeason;
use App\Entity\User;
use App\Enum\FeePaidBy;
use App\Security\Voter\SeasonVoter;
use App\Security\Voter\SchoolVoter;
use App\Service\Season\SeasonService;
use App\Service\SchoolContextService;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\StripeClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private readonly SchoolContextService $schoolContext,
        private readonly EntityManagerInterface $em,
        private readonly SeasonService $seasonService,
    ) {
    }

    #[Route('', name: 'school_settings', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::UPDATE, $school);

        $slugger       = new AsciiSlugger();
        $suggestedSlug = strtolower($slugger->slug($school->getName())->toString());

        if ($request->isMethod('POST')) {
            $school->setName((string) $request->request->get('name', $school->getName()));
            $school->setWebsiteUrl($request->request->get('websiteUrl') ?: null);
            $school->setContactEmail($request->request->get('contactEmail') ?: null);
            $school->setPhone($request->request->get('phone') ?: null);
            $school->setDescription($request->request->get('description') ?: null);
            $school->setSchedule($request->request->get('schedule') ?: null);
            $school->setPricing($request->request->get('pricing') ?: null);
            $school->setReadAndCheck($request->request->get('readAndCheck') ?: null);

            $slugRaw = $request->request->get('slug') ?: null;
            if ($slugRaw) {
                $slug = strtolower($slugger->slug($slugRaw)->toString());
                if ($slug !== $school->getCurrentSlug()) {
                    $conflict = $this->em->createQueryBuilder()
                        ->select('COUNT(t.id)')
                        ->from(School::class, 't')
                        ->where('t.currentSlug = :slug')
                        ->andWhere('t.id != :id')
                        ->setParameter('slug', $slug)
                        ->setParameter('id', $school->getId())
                        ->getQuery()->getSingleScalarResult();

                    if ($conflict > 0) {
                        $this->addFlash('error', 'Cet identifiant public est déjà utilisé par une autre école.');
                        return $this->redirectToRoute('school_settings');
                    }

                    $prev = $school->getPreviousSlugs() ?? [];
                    if ($school->getCurrentSlug()) {
                        $prev[] = $school->getCurrentSlug();
                    }
                    $school->setPreviousSlugs(array_values(array_unique($prev)));
                    $school->setCurrentSlug($slug);
                }
            }

            $uploadDir = $this->getParameter('kernel.project_dir') . '/public/media/schools/' . $school->getId();
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }

            /** @var UploadedFile|null $photo */
            $photo = $request->files->get('photo');
            if ($photo instanceof UploadedFile) {
                $filename = 'photo.' . $photo->guessExtension();
                $photo->move($uploadDir, $filename);
                $school->setAvatarPath('media/schools/' . $school->getId() . '/' . $filename);
            }

            /** @var UploadedFile|null $logo */
            $logo = $request->files->get('logo');
            if ($logo instanceof UploadedFile) {
                $filename = 'logo.' . $logo->guessExtension();
                $logo->move($uploadDir, $filename);
                $school->setLogoPath('media/schools/' . $school->getId() . '/' . $filename);
            }

            $school->setUpdatedAt(new \DateTimeImmutable());
            $this->em->flush();
            $this->addFlash('success', 'Paramètres généraux mis à jour.');

            return $this->redirectToRoute('school_settings');
        }

        return $this->render('school/settings/index.html.twig', [
            'school'          => $school,
            'suggestedSlug' => $suggestedSlug,
        ]);
    }

    #[Route('/images/upload', name: 'school_settings_image_upload', methods: ['POST'])]
    public function schoolImageUpload(Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            return $this->json(['success' => false, 'error' => 'Accès refusé.'], 403);
        }

        $this->denyAccessUnlessGranted(SchoolVoter::UPDATE, $school);

        $field = $request->request->get('field');
        if (!in_array($field, ['photo', 'logo'], true)) {
            return $this->json(['success' => false, 'error' => 'Champ invalide.'], 400);
        }

        /** @var ?UploadedFile $file */
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->json(['success' => false, 'error' => 'Aucun fichier reçu.'], 400);
        }

        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($file->getMimeType(), $allowed, true)) {
            return $this->json(['success' => false, 'error' => 'Format non supporté (jpg, png, webp, gif uniquement).'], 400);
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            return $this->json(['success' => false, 'error' => 'Fichier trop volumineux (max 5 Mo).'], 400);
        }

        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/school/' . $school->getId();
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $oldPath = $field === 'photo' ? $school->getAvatarPath() : $school->getLogoPath();

        if ($oldPath && str_starts_with($oldPath, 'uploads/school/')) {
            $oldFull = $this->getParameter('kernel.project_dir') . '/public/' . $oldPath;
            if (file_exists($oldFull)) {
                unlink($oldFull);
            }
        }

        $slugger  = new AsciiSlugger();
        $filename = $field . '-' . $slugger->slug($school->getId()) . '-' . uniqid() . '.' . $file->guessExtension();
        $file->move($uploadDir, $filename);

        $path = 'uploads/school/' . $school->getId() . '/' . $filename;
        if ($field === 'photo') {
            $school->setAvatarPath($path);
        } else {
            $school->setLogoPath($path);
        }

        $school->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json(['success' => true, 'url' => '/' . $path]);
    }

    #[Route('/images/delete', name: 'school_settings_image_delete', methods: ['POST'])]
    public function schoolImageDelete(Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            return $this->json(['success' => false, 'error' => 'Accès refusé.'], 403);
        }

        $this->denyAccessUnlessGranted(SchoolVoter::UPDATE, $school);

        $field = $request->request->get('field');
        if (!in_array($field, ['photo', 'logo'], true)) {
            return $this->json(['success' => false, 'error' => 'Champ invalide.'], 400);
        }

        if ($field === 'photo') {
            $oldPath = $school->getAvatarPath();
            $school->setAvatarPath(null);
        } else {
            $oldPath = $school->getLogoPath();
            $school->setLogoPath(null);
        }

        if ($oldPath) {
            $oldFull = $this->getParameter('kernel.project_dir') . '/public/' . $oldPath;
            if (file_exists($oldFull)) {
                unlink($oldFull);
            }
        }

        $school->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/legal', name: 'school_settings_legal', methods: ['GET', 'POST'])]
    public function legal(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::UPDATE, $school);

        if ($request->isMethod('POST')) {
            $school->setCurrency($request->request->get('currency', 'EUR'));
            $school->setCompanyName($request->request->get('companyName') ?: null);
            $school->setSiret($request->request->get('siret') ?: null);
            $school->setIban($request->request->get('iban') ?: null);
            $school->setApeNaf($request->request->get('apeNaf') ?: null);
            $school->setIsCollectingVat((bool) $request->request->get('isCollectingVat'));
            $school->setVatNumber($request->request->get('vatNumber') ?: null);
            $school->setInvoiceAddress($request->request->get('invoiceAddress') ?: null);
            $school->setAddressText($request->request->get('addressText') ?: null);
            $school->setAddressLat($request->request->get('addressLat') ?: null);
            $school->setAddressLng($request->request->get('addressLng') ?: null);

            $feePaidBy = FeePaidBy::tryFrom($request->request->get('feePaidBy', 'student'));
            if ($feePaidBy) {
                $school->setFeePaidBy($feePaidBy);
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
            $school->setPaymentMethods($paymentMethods);

            $school->setUpdatedAt(new \DateTimeImmutable());
            $this->em->flush();
            $this->addFlash('success', 'Informations légales mises à jour.');

            return $this->redirectToRoute('school_settings_legal');
        }

        return $this->render('school/settings/legal.html.twig', ['school' => $school]);
    }

    #[Route('/stripe', name: 'school_settings_stripe', methods: ['GET'])]
    public function stripe(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::CONFIGURE_STRIPE, $school);

        return $this->render('school/settings/stripe.html.twig', ['school' => $school]);
    }

    #[Route('/stripe/portal', name: 'school_settings_stripe_portal', methods: ['GET'])]
    public function stripePortal(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::CONFIGURE_STRIPE, $school);

        $accountId = $school->getStripeAccountId();
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
        $hasRequirements = $school->getStripeAccountStatus()->value !== 'active';
        $redirectUrl     = ($loginUrl && !$hasRequirements) ? $loginUrl : $accountLink->url;

        return $this->redirect($redirectUrl);
    }

    #[Route('/saisons', name: 'school_settings_seasons', methods: ['GET'])]
    public function seasons(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::UPDATE, $school);

        $seasons = $this->em->getRepository(Season::class)->findBy(
            ['schoolId' => $school->getId()],
            ['createdAt' => 'DESC'],
        );

        $seasonIds = array_map(fn(Season $s) => $s->getId(), $seasons);

        $orderedSeasonIds = $seasonIds ? array_column(
            $this->em->createQuery(
                'SELECT DISTINCT o.seasonId FROM App\Entity\Order o WHERE o.seasonId IN (:ids)'
            )->setParameter('ids', $seasonIds)->getScalarResult(),
            'seasonId'
        ) : [];

        $enrolledSeasonIds = $seasonIds ? array_column(
            $this->em->createQuery(
                'SELECT DISTINCT sps.seasonId FROM App\Entity\SchoolProfileSeason sps WHERE sps.seasonId IN (:ids)'
            )->setParameter('ids', $seasonIds)->getScalarResult(),
            'seasonId'
        ) : [];

        $nonDeletableIds = array_unique(array_merge($orderedSeasonIds, $enrolledSeasonIds));

        return $this->render('school/settings/seasons.html.twig', [
            'school'          => $school,
            'seasons'         => $seasons,
            'nonDeletableIds' => $nonDeletableIds,
        ]);
    }

    #[Route('/saisons/{seasonId}/delete', name: 'school_settings_season_delete', methods: ['POST'])]
    public function seasonDelete(string $seasonId, Request $request): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::UPDATE, $school);

        $season = $this->em->getRepository(Season::class)->find($seasonId);

        if ($season === null || $season->getSchoolId() !== $school->getId()) {
            throw $this->createNotFoundException('Saison introuvable.');
        }

        if (!$this->isCsrfTokenValid('season_delete_' . $seasonId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token invalide.');
            return $this->redirectToRoute('school_settings_seasons');
        }

        $hasOrders = (int) $this->em->createQuery(
            'SELECT COUNT(o.id) FROM App\Entity\Order o WHERE o.seasonId = :sid'
        )->setParameter('sid', $seasonId)->getSingleScalarResult() > 0;

        $hasEnrolled = (int) $this->em->createQuery(
            'SELECT COUNT(sps.id) FROM App\Entity\SchoolProfileSeason sps WHERE sps.seasonId = :sid'
        )->setParameter('sid', $seasonId)->getSingleScalarResult() > 0;

        if ($hasOrders || $hasEnrolled) {
            $this->addFlash('error', 'Impossible de supprimer cette saison : des élèves ou des paiements y sont rattachés.');
            return $this->redirectToRoute('school_settings_seasons');
        }

        $this->em->remove($season);
        $this->em->flush();

        $this->addFlash('success', 'Saison supprimée.');
        return $this->redirectToRoute('school_settings_seasons');
    }

    #[Route('/season/{id}/images/upload', name: 'school_settings_season_image_upload', methods: ['POST'])]
    public function seasonImageUpload(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();
        $season = $this->em->getRepository(Season::class)->find($id);

        if ($school === null || $season === null || $season->getSchoolId() !== $school->getId()) {
            return $this->json(['success' => false, 'error' => 'Accès refusé.'], 403);
        }

        $this->denyAccessUnlessGranted(SeasonVoter::UPDATE, $season);

        $field = $request->request->get('field');
        if (!in_array($field, ['planningImage', 'packagesImage'], true)) {
            return $this->json(['success' => false, 'error' => 'Champ invalide.'], 400);
        }

        /** @var ?UploadedFile $file */
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->json(['success' => false, 'error' => 'Aucun fichier reçu.'], 400);
        }

        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($file->getMimeType(), $allowed, true)) {
            return $this->json(['success' => false, 'error' => 'Format non supporté (jpg, png, webp, gif uniquement).'], 400);
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            return $this->json(['success' => false, 'error' => 'Fichier trop volumineux (max 5 Mo).'], 400);
        }

        $slugger   = new AsciiSlugger();
        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/seasons/' . $school->getId();
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $oldPath = $field === 'planningImage'
            ? $season->getPlanningImagePath()
            : $season->getPackagesImagePath();

        $suffix       = $field === 'planningImage' ? 'planning' : 'packages';
        $safeFilename = $slugger->slug($season->getId()) . '-' . $suffix . '-' . uniqid();
        $filename     = $safeFilename . '.' . $file->guessExtension();
        $file->move($uploadDir, $filename);

        if ($oldPath) {
            $oldFull = $this->getParameter('kernel.project_dir') . '/public/' . $oldPath;
            if (file_exists($oldFull)) {
                unlink($oldFull);
            }
        }

        $path = 'uploads/seasons/' . $school->getId() . '/' . $filename;
        if ($field === 'planningImage') {
            $season->setPlanningImagePath($path);
        } else {
            $season->setPackagesImagePath($path);
        }

        $season->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json(['success' => true, 'path' => $path, 'url' => '/' . $path]);
    }

    #[Route('/season/{id}/images/delete', name: 'school_settings_season_image_delete', methods: ['POST'])]
    public function seasonImageDelete(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();
        $season = $this->em->getRepository(Season::class)->find($id);

        if ($school === null || $season === null || $season->getSchoolId() !== $school->getId()) {
            return $this->json(['success' => false, 'error' => 'Accès refusé.'], 403);
        }

        $this->denyAccessUnlessGranted(SeasonVoter::UPDATE, $season);

        $field = $request->request->get('field');
        if (!in_array($field, ['planningImage', 'packagesImage'], true)) {
            return $this->json(['success' => false, 'error' => 'Champ invalide.'], 400);
        }

        if ($field === 'planningImage') {
            $oldPath = $season->getPlanningImagePath();
            $season->setPlanningImagePath(null);
        } else {
            $oldPath = $season->getPackagesImagePath();
            $season->setPackagesImagePath(null);
        }

        if ($oldPath) {
            $fullPath = $this->getParameter('kernel.project_dir') . '/public/' . $oldPath;
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
        }

        $season->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/rooms', name: 'school_settings_rooms', methods: ['GET', 'POST'])]
    public function rooms(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::UPDATE, $school);

        $seasonId = $school->getCurrentSeasonId();
        $season   = $seasonId ? $this->em->getRepository(Season::class)->find($seasonId) : null;

        if ($season !== null && $request->isMethod('POST')) {
            $method = $request->request->get('_method');

            if ($method === 'DELETE') {
                $roomId = $request->request->get('roomId');
                $room   = $this->em->getRepository(Room::class)->find($roomId);

                if ($room !== null && $room->getSchoolId() === $school->getId()) {
                    $this->em->remove($room);
                    $this->em->flush();
                }
            } else {
                $room = new Room();
                $room->setSchoolId($school->getId());
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
            'school'   => $school,
            'season' => $season,
            'rooms'  => $rooms,
        ]);
    }

    #[Route('/stripe/payments', name: 'school_settings_stripe_payments', methods: ['GET'])]
    public function stripePayments(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::CONFIGURE_STRIPE, $school);

        // Stripe checkout sessions are fetched directly via the Stripe SDK.
        // Delegate listing to the service when that method is available,
        // or pass an empty array so the template can handle the not-yet-configured state.
        $sessions = [];

        return $this->render('school/settings/stripe_payments.html.twig', [
            'school'     => $school,
            'sessions' => $sessions,
        ]);
    }

    #[Route('/season', name: 'school_settings_season_current', methods: ['GET'])]
    public function seasonCurrent(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::UPDATE, $school);

        $seasonId = $school->getCurrentSeasonId();

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
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::UPDATE, $school);

        if ($request->isMethod('POST')) {
            $season = $this->seasonService->createSeason($school, [
                'name'    => $request->request->get('name'),
                'startAt' => new \DateTimeImmutable((string) $request->request->get('startAt')),
                'endAt'   => new \DateTimeImmutable((string) $request->request->get('endAt')),
            ]);

            $this->addFlash('success', 'Saison créée avec succès.');

            return $this->redirectToRoute('school_settings_season', ['id' => $season->getId()]);
        }

        return $this->render('school/settings/season_create.html.twig', ['school' => $school]);
    }

    #[Route('/season/{id}', name: 'school_settings_season', methods: ['GET', 'POST'])]
    public function season(string $id, Request $request): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $school   = $this->schoolContext->getCurrentSchool();
        $season = $this->em->getRepository(Season::class)->find($id);

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        if ($season === null || $season->getSchoolId() !== $school->getId()) {
            throw $this->createNotFoundException('Season not found.');
        }

        $this->denyAccessUnlessGranted(SeasonVoter::UPDATE, $season);

        if ($request->isMethod('POST')) {
            $section = $request->request->get('_section', 'general');

            if ($section === 'general') {
                $season->setName((string) $request->request->get('name', $season->getName()));
                if ($request->request->get('startAt')) {
                    $season->setStartAt(new \DateTimeImmutable((string) $request->request->get('startAt')));
                }
                if ($request->request->get('endAt')) {
                    $season->setEndAt(new \DateTimeImmutable((string) $request->request->get('endAt')));
                }
                $season->setVisible((bool) $request->request->get('visible', false));
                $season->setDescription($request->request->get('description') ?: null);
            }

            if ($section === 'registrations') {
                $season->setRegistrationFeeId($request->request->get('registrationFeeId') ?: null);
                $season->setRegistrationPaymentCondition($request->request->get('registrationPaymentCondition') ?: null);
                $season->setRegistrationPublicDescription($request->request->get('registrationPublicDescription') ?: null);
                foreach ([
                    'preRegistrationsStartAt' => 'setPreRegistrationsStartAt',
                    'preRegistrationsEndAt'   => 'setPreRegistrationsEndAt',
                    'registrationsStartAt'    => 'setRegistrationsStartAt',
                    'registrationsEndAt'      => 'setRegistrationsEndAt',
                ] as $field => $setter) {
                    $val = $request->request->get($field);
                    $season->$setter($val ? new \DateTimeImmutable($val) : null);
                }
            }

            if ($section === 'closures') {
                $raw = $request->request->get('closures', '[]');
                $decoded = json_decode((string) $raw, true);
                $season->setClosures(is_array($decoded) ? $decoded : []);
            }

            if ($section === 'images') {
                $slugger   = new AsciiSlugger();
                $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/seasons/' . $school->getId();
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                /** @var ?UploadedFile $planningFile */
                $planningFile = $request->files->get('planningImage');
                if ($planningFile instanceof UploadedFile) {
                    $safeFilename = $slugger->slug($season->getId()) . '-planning-' . uniqid();
                    $filename     = $safeFilename . '.' . $planningFile->guessExtension();
                    $planningFile->move($uploadDir, $filename);
                    $season->setPlanningImagePath('uploads/seasons/' . $school->getId() . '/' . $filename);
                }
                /** @var ?UploadedFile $packagesFile */
                $packagesFile = $request->files->get('packagesImage');
                if ($packagesFile instanceof UploadedFile) {
                    $safeFilename = $slugger->slug($season->getId()) . '-packages-' . uniqid();
                    $filename     = $safeFilename . '.' . $packagesFile->guessExtension();
                    $packagesFile->move($uploadDir, $filename);
                    $season->setPackagesImagePath('uploads/seasons/' . $school->getId() . '/' . $filename);
                }
            }

            $season->setUpdatedAt(new \DateTimeImmutable());
            $this->em->flush();
            $this->addFlash('success', 'Saison mise à jour.');

            return $this->redirectToRoute('school_settings_season', ['id' => $id]);
        }

        $packages = $this->em->getRepository(\App\Entity\Package::class)->findBy(
            ['seasonId' => $season->getId(), 'deletedAt' => null],
            ['name' => 'ASC']
        );

        return $this->render('school/settings/season.html.twig', [
            'school'   => $school,
            'season'   => $season,
            'packages' => $packages,
        ]);
    }

    #[Route('/season/{id}/stats', name: 'school_settings_season_stats', methods: ['GET'])]
    public function seasonStats(string $id): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $school   = $this->schoolContext->getCurrentSchool();
        $season = $this->em->getRepository(Season::class)->find($id);

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        if ($season === null || $season->getSchoolId() !== $school->getId()) {
            throw $this->createNotFoundException('Season not found.');
        }

        $this->denyAccessUnlessGranted(SeasonVoter::VIEW, $season);

        $registrations = $this->em->getRepository(SchoolProfileSeason::class)->findBy([
            'seasonId' => $season->getId(),
            'schoolId'   => $school->getId(),
        ]);

        return $this->render('school/settings/season_stats.html.twig', [
            'school'          => $school,
            'season'        => $season,
            'registrations' => $registrations,
        ]);
    }
}
