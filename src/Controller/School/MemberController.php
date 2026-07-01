<?php

declare(strict_types=1);

namespace App\Controller\School;

use App\Entity\Order;
use App\Entity\Package;
use App\Entity\PaymentSchedule;
use App\Entity\Profile;
use App\Entity\PriceModifier;
use App\Entity\Season;
use App\Entity\SchoolProfilePackage;
use App\Entity\SchoolProfileSeason;
use App\Entity\User;
use App\Enum\PackageStatus;
use App\Enum\ScheduleStatus;
use App\Enum\SchoolProfileStatus;
use App\Enum\SchoolRole;
use App\Form\School\MemberType;
use App\Security\Voter\SchoolVoter;
use App\Service\Email\EmailService;
use App\Service\Member\MemberService;
use App\Service\SchoolContextService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/school/members')]
#[IsGranted('ROLE_USER')]
final class MemberController extends AbstractController
{
    public function __construct(
        private readonly SchoolContextService $schoolContext,
        private readonly EntityManagerInterface $em,
        private readonly MemberService $memberService,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EmailService $emailService,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    #[Route('/{type}', name: 'school_members_list', methods: ['GET'],
        requirements: ['type' => 'all|students|teachers|admins'])]
    public function list(string $type, Request $request): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolMember($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

        if ($school->getCurrentSeasonId() === null) {
            return $this->redirectToRoute('school_settings_season_create');
        }

        // Season resolution
        $session  = $request->getSession();
        $seasonId = $request->query->get('season');

        if ($seasonId !== null) {
            $season = $this->em->getRepository(Season::class)->find($seasonId);
            if ($season !== null && $season->getSchoolId() !== $school->getId()) {
                $season = null;
            }
            if ($season !== null) {
                $session->set('school.season_id', $season->getId());
            }
        } else {
            $storedId = $session->get('school.season_id');
            if ($storedId) {
                return $this->redirectToRoute('school_members_list', ['type' => $type, 'season' => $storedId]);
            }
            $season = null;
        }

        $roleMap = [
            'students' => SchoolRole::Student,
            'teachers' => SchoolRole::Teacher,
            'admins'   => SchoolRole::School,
        ];

        // Filters
        $search                   = trim((string) $request->query->get('q', ''));
        $filterHasUser            = (string) $request->query->get('has_user', '');
        $filterRegistrationStatus = (string) $request->query->get('registration_status', '');
        $filterActivity           = (string) $request->query->get('activity', '');
        $filterPayStatus          = (string) $request->query->get('payment_status', '');
        $filterPkgType            = (string) $request->query->get('package_type', '');
        $filterPackageId          = (string) $request->query->get('package_id', '');
        $filterPayLoc             = (string) $request->query->get('payment_location', '');
        $filterOnsiteMethod       = (string) $request->query->get('onsite_method', '');
        $filterOnlineMethod       = (string) $request->query->get('online_method', '');
        $filterPastDue            = (string) $request->query->get('past_due', '');
        $filterHasInjury          = (string) $request->query->get('has_injury', '');

        // Build SchoolProfileSeason query
        $qb = $this->em->createQueryBuilder()
            ->select('tps')
            ->from(SchoolProfileSeason::class, 'tps')
            ->where('tps.schoolId = :schoolId')
            ->setParameter('schoolId', $school->getId());

        if ($season !== null) {
            $qb->andWhere('tps.seasonId = :seasonId')
               ->setParameter('seasonId', $season->getId());
        }

        if ($type !== 'all') {
            $qb->andWhere('tps.role = :role')
               ->setParameter('role', $roleMap[$type]);
        }

        if ($filterRegistrationStatus !== '') {
            $statusEnum = \App\Enum\SchoolProfileStatus::tryFrom($filterRegistrationStatus);
            if ($statusEnum !== null) {
                $qb->andWhere('tps.registrationStatus = :status')
                   ->setParameter('status', $statusEnum);
            }
        }

        $qb->orderBy('tps.createdAt', 'ASC');

        /** @var SchoolProfileSeason[] $members */
        $members = $qb->getQuery()->getResult();

        // Load and attach profiles
        $profileIds = array_unique(array_map(fn(SchoolProfileSeason $m) => $m->getProfileId(), $members));
        $profilesById = [];
        if (!empty($profileIds)) {
            $profileList = $this->em->createQuery(
                'SELECT p FROM App\Entity\Profile p WHERE p.id IN (:ids)'
            )->setParameter('ids', $profileIds)->getResult();
            foreach ($profileList as $p) {
                $profilesById[$p->getId()] = $p;
            }
        }
        foreach ($members as $sps) {
            $sps->setProfile($profilesById[$sps->getProfileId()] ?? null);
        }

        // Sort by last name / first name via transient profile
        usort($members, static function (SchoolProfileSeason $a, SchoolProfileSeason $b): int {
            $pA = $a->getProfile();
            $pB = $b->getProfile();
            $nameA = ($pA?->getLastName() ?? '') . ($pA?->getFirstName() ?? '');
            $nameB = ($pB?->getLastName() ?? '') . ($pB?->getFirstName() ?? '');
            return strcasecmp($nameA, $nameB);
        });

        // Search filter
        if ($search !== '') {
            $searchLower = mb_strtolower($search);
            $members = array_values(array_filter(
                $members,
                static function (SchoolProfileSeason $m) use ($searchLower): bool {
                    $p = $m->getProfile();
                    if ($p === null) {
                        return false;
                    }
                    $name = mb_strtolower($p->getFirstName() . ' ' . $p->getLastName());
                    return str_contains($name, $searchLower)
                        || str_contains((string) $p->getPhone(), $searchLower);
                }
            ));
        }

        if ($filterHasUser === 'yes') {
            $members = array_values(array_filter(
                $members,
                static fn(SchoolProfileSeason $m): bool => $m->getUser() !== null
            ));
        } elseif ($filterHasUser === 'no') {
            $members = array_values(array_filter(
                $members,
                static fn(SchoolProfileSeason $m): bool => $m->getUser() === null
            ));
        }

        // Build profileId <→ spsId mapping for financial queries
        $profileToSps = [];
        $spsToProfile = [];
        foreach ($members as $sps) {
            $profileToSps[$sps->getProfileId()] = $sps->getId();
            $spsToProfile[$sps->getId()]         = $sps->getProfileId();
        }
        $memberProfileIds = array_keys($profileToSps);

        // tpsMap = spsId → sps (for template backward-compat)
        $tpsMap = [];
        foreach ($members as $sps) {
            $tpsMap[$sps->getId()] = $sps;
        }

        // Financial data, packages, activities
        $financialMap       = [];
        $nextScheduleMap    = [];
        $nbSchedulesMap     = [];
        $lastPaymentDateMap = [];
        $buyerMap           = [];
        $packagesMap        = [];
        $paymentMethodMap   = [];
        $pkgTypesMap        = [];
        $usedPkgTypes       = [];
        $usedOnsiteMethods  = [];
        $usedOnlineMethods  = [];
        $allPackages        = [];
        $allActivities      = [];
        $allReductions      = [];
        $allIncrements      = [];

        if ($season !== null && !empty($memberProfileIds)) {

            // Order aggregates keyed by profileId → transform to spsId
            $orderAgg = $this->em->createQuery(
                'SELECT o.profileId, SUM(o.totalAmount) as total, SUM(o.paidAmount) as paid, COUNT(o.id) as ordersCount
                 FROM App\Entity\Order o
                 WHERE o.schoolId = :schoolId
                   AND o.seasonId = :seasonId
                   AND o.deletedAt IS NULL
                   AND o.profileId IN (:ids)
                 GROUP BY o.profileId'
            )
            ->setParameter('schoolId', $school->getId())
            ->setParameter('seasonId', $season->getId())
            ->setParameter('ids', $memberProfileIds)
            ->getArrayResult();

            foreach ($orderAgg as $row) {
                $spsId = $profileToSps[$row['profileId']] ?? null;
                if ($spsId === null) {
                    continue;
                }
                $total = (int) $row['total'];
                $paid  = (int) $row['paid'];
                $left  = $total - $paid;

                if ($total === 0) {
                    $payStatus = null;
                } elseif ($paid > $total) {
                    $payStatus = 'overpaid';
                } elseif ($paid === $total) {
                    $payStatus = 'paid';
                } elseif ($paid === 0) {
                    $payStatus = 'unpaid';
                } else {
                    $payStatus = 'partially_paid';
                }

                $financialMap[$spsId] = [
                    'total'       => $total,
                    'paid'        => $paid,
                    'left'        => max(0, $left),
                    'status'      => $payStatus,
                    'ordersCount' => (int) $row['ordersCount'],
                ];
            }

            // Order IDs for downstream queries
            $orderIdRows = $this->em->createQuery(
                'SELECT o.id, o.profileId
                 FROM App\Entity\Order o
                 WHERE o.schoolId = :schoolId
                   AND o.seasonId = :seasonId
                   AND o.deletedAt IS NULL
                   AND o.profileId IN (:ids)'
            )
            ->setParameter('schoolId', $school->getId())
            ->setParameter('seasonId', $season->getId())
            ->setParameter('ids', $memberProfileIds)
            ->getArrayResult();

            $orderToProfile = [];
            $orderIds       = [];
            foreach ($orderIdRows as $row) {
                $orderToProfile[$row['id']] = $row['profileId'];
                $orderIds[]                 = $row['id'];
            }

            if (!empty($orderIds)) {
                // Next pending schedule per member
                $schedRows = $this->em->createQuery(
                    'SELECT ps.orderId, MIN(ps.dueAt) as nextDue
                     FROM App\Entity\PaymentSchedule ps
                     WHERE ps.orderId IN (:orderIds)
                       AND ps.status = :status
                     GROUP BY ps.orderId'
                )
                ->setParameter('orderIds', $orderIds)
                ->setParameter('status', ScheduleStatus::Pending)
                ->getArrayResult();

                foreach ($schedRows as $row) {
                    $pid   = $orderToProfile[$row['orderId']] ?? null;
                    $spsId = $pid ? ($profileToSps[$pid] ?? null) : null;
                    if ($spsId === null) {
                        continue;
                    }
                    $due = $row['nextDue'];
                    if (is_string($due)) {
                        $due = new \DateTimeImmutable($due);
                    } elseif ($due instanceof \DateTime) {
                        $due = \DateTimeImmutable::createFromMutable($due);
                    }
                    if (!$due instanceof \DateTimeImmutable) {
                        continue;
                    }
                    if (!isset($nextScheduleMap[$spsId]) || $due < $nextScheduleMap[$spsId]) {
                        $nextScheduleMap[$spsId] = $due;
                    }
                }

                // Nb schedules per member
                $schedCountRows = $this->em->createQuery(
                    'SELECT ps.orderId, COUNT(ps.id) as cnt
                     FROM App\Entity\PaymentSchedule ps
                     WHERE ps.orderId IN (:orderIds)
                     GROUP BY ps.orderId'
                )
                ->setParameter('orderIds', $orderIds)
                ->getArrayResult();

                foreach ($schedCountRows as $row) {
                    $pid   = $orderToProfile[$row['orderId']] ?? null;
                    $spsId = $pid ? ($profileToSps[$pid] ?? null) : null;
                    if ($spsId === null) {
                        continue;
                    }
                    $nbSchedulesMap[$spsId] = ($nbSchedulesMap[$spsId] ?? 0) + (int) $row['cnt'];
                }

                // Last payment date
                $lastPaidRows = $this->em->createQuery(
                    'SELECT py.orderId, MAX(py.paidAt) as lastPaid
                     FROM App\Entity\Payment py
                     WHERE py.orderId IN (:orderIds)
                       AND py.paidAt IS NOT NULL
                     GROUP BY py.orderId'
                )
                ->setParameter('orderIds', $orderIds)
                ->getArrayResult();

                foreach ($lastPaidRows as $row) {
                    $pid   = $orderToProfile[$row['orderId']] ?? null;
                    $spsId = $pid ? ($profileToSps[$pid] ?? null) : null;
                    if ($spsId === null) {
                        continue;
                    }
                    $lastPaid = $row['lastPaid'];
                    if (is_string($lastPaid)) {
                        $lastPaid = new \DateTimeImmutable($lastPaid);
                    } elseif ($lastPaid instanceof \DateTime) {
                        $lastPaid = \DateTimeImmutable::createFromMutable($lastPaid);
                    }
                    if (!$lastPaid instanceof \DateTimeImmutable) {
                        continue;
                    }
                    if (!isset($lastPaymentDateMap[$spsId]) || $lastPaid > $lastPaymentDateMap[$spsId]) {
                        $lastPaymentDateMap[$spsId] = $lastPaid;
                    }
                }

                // Payment methods
                $pmRows = $this->em->createQuery(
                    'SELECT py.orderId, py.method
                     FROM App\Entity\Payment py
                     WHERE py.orderId IN (:orderIds)
                       AND py.paidAt IS NOT NULL'
                )
                ->setParameter('orderIds', $orderIds)
                ->getArrayResult();

                foreach ($pmRows as $row) {
                    $pid   = $orderToProfile[$row['orderId']] ?? null;
                    $spsId = $pid ? ($profileToSps[$pid] ?? null) : null;
                    if ($spsId === null) {
                        continue;
                    }
                    $m   = $row['method'];
                    $val = $m instanceof \UnitEnum ? $m->value : (string) $m;
                    if (!isset($paymentMethodMap[$spsId]) || !in_array($val, $paymentMethodMap[$spsId], true)) {
                        $paymentMethodMap[$spsId][] = $val;
                    }
                }

                // External buyer names
                $memberProfileIdMap = [];
                foreach ($members as $m) {
                    $memberProfileIdMap[$m->getId()] = $m->getProfileId();
                }

                $externalBuyerPids = [];
                $memberBuyerPids   = [];
                foreach ($orderIdRows as $row) {
                    $ordId    = $row['id'];
                    $buyerPid = $row['profileId'];
                    $pid      = $orderToProfile[$ordId] ?? null;
                    $spsId    = $pid ? ($profileToSps[$pid] ?? null) : null;
                    if ($spsId !== null && $buyerPid !== $memberProfileIdMap[$spsId]) {
                        $externalBuyerPids[$buyerPid]       = true;
                        $memberBuyerPids[$spsId][$buyerPid] = true;
                    }
                }

                if (!empty($externalBuyerPids)) {
                    $profileRows = $this->em->createQuery(
                        'SELECT pr.id, pr.firstName, pr.lastName
                         FROM App\Entity\Profile pr
                         WHERE pr.id IN (:ids)'
                    )
                    ->setParameter('ids', array_keys($externalBuyerPids))
                    ->getArrayResult();

                    $buyerNames = [];
                    foreach ($profileRows as $row) {
                        $buyerNames[$row['id']] = trim($row['firstName'] . ' ' . $row['lastName']);
                    }

                    foreach ($memberBuyerPids as $spsId => $pids) {
                        $names = array_values(array_filter(
                            array_map(static fn(string $pid): ?string => $buyerNames[$pid] ?? null, array_keys($pids))
                        ));
                        if (!empty($names)) {
                            $buyerMap[$spsId] = implode(', ', $names);
                        }
                    }
                }
            }

            // Packages count per member
            $pkgRows = $this->em->createQuery(
                'SELECT pkg.profileId, COUNT(pkg.id) as cnt
                 FROM App\Entity\SchoolProfilePackage pkg
                 WHERE pkg.schoolId = :schoolId
                   AND pkg.seasonId = :seasonId
                   AND pkg.profileId IN (:ids)
                   AND pkg.deletedAt IS NULL
                   AND pkg.status = :status
                 GROUP BY pkg.profileId'
            )
            ->setParameter('schoolId', $school->getId())
            ->setParameter('seasonId', $season->getId())
            ->setParameter('ids', $memberProfileIds)
            ->setParameter('status', PackageStatus::Active)
            ->getArrayResult();

            foreach ($pkgRows as $row) {
                $spsId = $profileToSps[$row['profileId']] ?? null;
                if ($spsId !== null) {
                    $packagesMap[$spsId] = (int) $row['cnt'];
                }
            }

            // Package types per member
            $pkgTypeRows = $this->em->createQuery(
                'SELECT pkg.profileId, pkg.type
                 FROM App\Entity\SchoolProfilePackage pkg
                 WHERE pkg.schoolId = :schoolId
                   AND pkg.seasonId = :seasonId
                   AND pkg.profileId IN (:ids)
                   AND pkg.deletedAt IS NULL'
            )
            ->setParameter('schoolId', $school->getId())
            ->setParameter('seasonId', $season->getId())
            ->setParameter('ids', $memberProfileIds)
            ->getArrayResult();

            $seenPkgTypes = [];
            foreach ($pkgTypeRows as $row) {
                $spsId = $profileToSps[$row['profileId']] ?? null;
                if ($spsId !== null) {
                    $pkgTypesMap[$spsId][] = $row['type'];
                    $seenPkgTypes[$row['type']] = true;
                }
            }
            $usedPkgTypes = array_keys($seenPkgTypes);

            // Payment methods split
            $seenOnsite = [];
            $seenOnline = [];
            foreach ($paymentMethodMap as $methods) {
                foreach ($methods as $method) {
                    if (str_starts_with($method, 'online_')) {
                        $seenOnline[$method] = true;
                    } else {
                        $seenOnsite[$method] = true;
                    }
                }
            }
            $usedOnsiteMethods = array_keys($seenOnsite);
            $usedOnlineMethods = array_keys($seenOnline);

            // Packages for the season (for specific package filter)
            $allPackages = $this->em->createQuery(
                'SELECT pkg FROM App\Entity\Package pkg
                 WHERE pkg.schoolId = :schoolId AND pkg.seasonId = :seasonId
                 ORDER BY pkg.name ASC'
            )
            ->setParameter('schoolId', $school->getId())
            ->setParameter('seasonId', $season->getId())
            ->getResult();
        }

        // PHP-side payment / package / schedule filters
        $now = new \DateTimeImmutable();

        if ($filterPayStatus !== '') {
            $members = array_values(array_filter(
                $members,
                static function (SchoolProfileSeason $m) use ($financialMap, $filterPayStatus): bool {
                    $fin = $financialMap[$m->getId()] ?? null;
                    return $fin === null ? $filterPayStatus === 'unpaid' : $fin['status'] === $filterPayStatus;
                }
            ));
        }

        if ($filterPayLoc !== '') {
            $members = array_values(array_filter(
                $members,
                static function (SchoolProfileSeason $m) use ($paymentMethodMap, $filterPayLoc): bool {
                    foreach ($paymentMethodMap[$m->getId()] ?? [] as $method) {
                        $isOnline = str_starts_with((string) $method, 'online_');
                        if ($filterPayLoc === 'online' && $isOnline) {
                            return true;
                        }
                        if ($filterPayLoc === 'onsite' && !$isOnline) {
                            return true;
                        }
                    }
                    return false;
                }
            ));
        }

        if ($filterPkgType !== '') {
            $members = array_values(array_filter(
                $members,
                static fn(SchoolProfileSeason $m): bool => in_array($filterPkgType, $pkgTypesMap[$m->getId()] ?? [], true)
            ));
        }

        if ($filterPastDue === 'yes') {
            $members = array_values(array_filter(
                $members,
                static function (SchoolProfileSeason $m) use ($nextScheduleMap, $now): bool {
                    $due = $nextScheduleMap[$m->getId()] ?? null;
                    return $due instanceof \DateTimeImmutable && $due < $now;
                }
            ));
        }

        if ($filterOnsiteMethod !== '') {
            $members = array_values(array_filter(
                $members,
                static fn(SchoolProfileSeason $m): bool => in_array($filterOnsiteMethod, $paymentMethodMap[$m->getId()] ?? [], true)
            ));
        }

        if ($filterOnlineMethod !== '') {
            $members = array_values(array_filter(
                $members,
                static fn(SchoolProfileSeason $m): bool => in_array($filterOnlineMethod, $paymentMethodMap[$m->getId()] ?? [], true)
            ));
        }

        if ($filterHasInjury === 'yes') {
            $members = array_values(array_filter(
                $members,
                static fn(SchoolProfileSeason $m): bool => (bool) ($m->getProfile()?->getInjuryWarning())
            ));
        } elseif ($filterHasInjury === 'no') {
            $members = array_values(array_filter(
                $members,
                static fn(SchoolProfileSeason $m): bool => !(bool) ($m->getProfile()?->getInjuryWarning())
            ));
        }

        if ($filterPackageId !== '' && $season !== null) {
            $pkgMemberProfileIds = array_column(
                $this->em->createQuery(
                    'SELECT tpp.profileId FROM App\Entity\SchoolProfilePackage tpp
                     WHERE tpp.packageId = :pkgId AND tpp.seasonId = :seasonId AND tpp.deletedAt IS NULL'
                )
                ->setParameter('pkgId', $filterPackageId)
                ->setParameter('seasonId', $season->getId())
                ->getArrayResult(),
                'profileId'
            );
            $members = array_values(array_filter(
                $members,
                static fn(SchoolProfileSeason $m): bool => in_array($m->getProfileId(), $pkgMemberProfileIds, true)
            ));
        }

        // Activities and price modifiers for the season
        if ($season !== null) {
            $priceModifiers = $this->em->createQuery(
                'SELECT pm FROM App\Entity\PriceModifier pm
                 WHERE pm.schoolId = :schoolId
                   AND (pm.seasonId = :seasonId OR pm.seasonId IS NULL)
                   AND pm.deletedAt IS NULL
                 ORDER BY pm.name ASC'
            )
            ->setParameter('schoolId', $school->getId())
            ->setParameter('seasonId', $season->getId())
            ->getResult();

            foreach ($priceModifiers as $pm) {
                if ($pm->getOperation()->value === 'subtract') {
                    $allReductions[] = $pm;
                } else {
                    $allIncrements[] = $pm;
                }
            }
        }

        $activeFilterCount = count(array_filter([
            $search, $filterHasUser, $filterRegistrationStatus, $filterActivity,
            $filterPayStatus, $filterPkgType, $filterPayLoc, $filterPastDue,
            $filterPackageId, $filterOnsiteMethod, $filterOnlineMethod, $filterHasInjury,
        ]));

        return $this->render('school/members/list.html.twig', [
            'school'               => $school,
            'type'                 => $type,
            'members'              => $members,
            'season'               => $season,
            'tpsMap'               => $tpsMap,
            'financialMap'         => $financialMap,
            'nextScheduleMap'      => $nextScheduleMap,
            'nbSchedulesMap'       => $nbSchedulesMap,
            'lastPaymentDateMap'   => $lastPaymentDateMap,
            'buyerMap'             => $buyerMap,
            'packagesMap'          => $packagesMap,
            'paymentMethodMap'     => $paymentMethodMap,
            'pkgTypesMap'          => $pkgTypesMap,
            'usedPkgTypes'         => $usedPkgTypes,
            'usedOnsiteMethods'    => $usedOnsiteMethods,
            'usedOnlineMethods'    => $usedOnlineMethods,
            'allPackages'          => $allPackages,
            'allActivities'        => $allActivities,
            'allReductions'        => $allReductions,
            'allIncrements'        => $allIncrements,
            'filters'              => [
                'q'                   => $search,
                'has_user'            => $filterHasUser,
                'registration_status' => $filterRegistrationStatus,
                'activity'            => $filterActivity,
                'payment_status'      => $filterPayStatus,
                'package_type'        => $filterPkgType,
                'payment_location'    => $filterPayLoc,
                'past_due'            => $filterPastDue,
                'package_id'          => $filterPackageId,
                'onsite_method'       => $filterOnsiteMethod,
                'online_method'       => $filterOnlineMethod,
                'has_injury'          => $filterHasInjury,
            ],
            'activeFilterCount'    => $activeFilterCount,
        ]);
    }

    #[Route('/detail/{id}', name: 'school_member_detail', methods: ['GET'])]
    public function detail(string $id, Request $request): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolMember($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

        /** @var SchoolProfileSeason|null $member */
        $member = $this->em->getRepository(SchoolProfileSeason::class)->find($id);

        if ($member === null || $member->getSchoolId() !== $school->getId()) {
            throw $this->createNotFoundException('Member not found.');
        }

        // Attach profile
        $profile = $this->em->getRepository(Profile::class)->find($member->getProfileId());
        $member->setProfile($profile);

        $season = $school->getCurrentSeasonId()
            ? $this->em->getRepository(Season::class)->find($school->getCurrentSeasonId())
            : null;

        $initialData = [
            'firstName'            => $profile?->getFirstName(),
            'lastName'             => $profile?->getLastName(),
            'dob'                  => $profile?->getDob(),
            'phone'                => $profile?->getPhone(),
            'addressText'          => $profile?->getAddressText(),
            'gender'               => $profile?->getGender()?->value,
            'note'                 => $member->getNote(),
            'registrationStatus'   => $member->getRegistrationStatus()?->value,
            'injuryWarning'        => $profile?->getInjuryWarning(),
            'emergencyName'        => $profile?->getEmergencyName(),
            'emergencyRelationship'=> $profile?->getEmergencyRelationship(),
            'emergencyEmail'       => $profile?->getEmergencyEmail(),
            'emergencyPhone'       => $profile?->getEmergencyPhone(),
        ];

        $form = $this->createForm(MemberType::class, $initialData);
        $form->get('email')->setData($profile?->getUser()?->getEmail());
        $form->get('consentAccepted')->setData($member->getConsentAccepted() ?? []);

        return $this->render('school/members/detail.html.twig', [
            'school' => $school,
            'member' => $member,
            'season' => $season,
            'tps'    => $member,
            'form'   => $form->createView(),
        ]);
    }

    #[Route('/detail/{id}/edit', name: 'school_member_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolMember($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::UPDATE, $school);

        /** @var SchoolProfileSeason|null $member */
        $member = $this->em->getRepository(SchoolProfileSeason::class)->find($id);

        if ($member === null || $member->getSchoolId() !== $school->getId()) {
            throw $this->createNotFoundException('Member not found.');
        }

        $profile = $this->em->getRepository(Profile::class)->find($member->getProfileId());
        $member->setProfile($profile);

        $season = $school->getCurrentSeasonId()
            ? $this->em->getRepository(Season::class)->find($school->getCurrentSeasonId())
            : null;

        $initialData = [
            'firstName'            => $profile?->getFirstName(),
            'lastName'             => $profile?->getLastName(),
            'dob'                  => $profile?->getDob(),
            'phone'                => $profile?->getPhone(),
            'addressText'          => $profile?->getAddressText(),
            'gender'               => $profile?->getGender()?->value,
            'note'                 => $member->getNote(),
            'registrationStatus'   => $member->getRegistrationStatus()?->value,
            'injuryWarning'        => $profile?->getInjuryWarning(),
            'emergencyName'        => $profile?->getEmergencyName(),
            'emergencyRelationship'=> $profile?->getEmergencyRelationship(),
            'emergencyEmail'       => $profile?->getEmergencyEmail(),
            'emergencyPhone'       => $profile?->getEmergencyPhone(),
        ];

        $form = $this->createForm(MemberType::class, $initialData);
        $form->get('email')->setData($profile?->getUser()?->getEmail());
        $form->get('consentAccepted')->setData($member->getConsentAccepted() ?? []);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $member->setNote($data['note'] ?: null);

            $statusValue = $data['registrationStatus'] ?? null;
            $member->setRegistrationStatus($statusValue ? SchoolProfileStatus::from($statusValue) : null);

            $consentAccepted = $form->get('consentAccepted')->getData();
            $member->setConsentAccepted($consentAccepted ?: null);

            if ($profile !== null) {
                $profile->setInjuryWarning($data['injuryWarning'] ?? null);
                $profile->setEmergencyName($data['emergencyName'] ?? null);
                $profile->setEmergencyRelationship($data['emergencyRelationship'] ?? null);
                $profile->setEmergencyEmail($data['emergencyEmail'] ?? null);
                $profile->setEmergencyPhone($data['emergencyPhone'] ?? null);
            }

            $this->em->flush();
            $this->addFlash('success', 'Fiche mise à jour.');

            return $this->redirectToRoute('school_member_detail', ['id' => $id]);
        }

        return $this->render('school/members/detail.html.twig', [
            'school' => $school,
            'member' => $member,
            'season' => $season,
            'tps'    => $member,
            'form'   => $form->createView(),
        ]);
    }

    #[Route('/detail/{id}/delete', name: 'school_member_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolMember($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::UPDATE, $school);

        if (!$this->isCsrfTokenValid('delete_member_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $member = $this->em->getRepository(SchoolProfileSeason::class)->find($id);

        if ($member === null || $member->getSchoolId() !== $school->getId()) {
            throw $this->createNotFoundException('Member not found.');
        }

        $type = match ($member->getRole()) {
            SchoolRole::Teacher => 'teachers',
            SchoolRole::School  => 'admins',
            default             => 'students',
        };

        $profileId = $member->getProfileId();

        $this->em->createQuery(
            'DELETE FROM App\Entity\SchoolProfilePackage tpp WHERE tpp.profileId = :pid AND tpp.schoolId = :sid'
        )
        ->setParameter('pid', $profileId)
        ->setParameter('sid', $school->getId())
        ->execute();

        $this->em->createQuery(
            'DELETE FROM App\Entity\EventOccurenceProfile eop WHERE eop.profileId = :pid'
        )
        ->setParameter('pid', $profileId)
        ->execute();

        $this->em->createQuery(
            'DELETE FROM App\Entity\SchoolProfileGalaParticipation tpgp WHERE tpgp.profileId = :pid'
        )
        ->setParameter('pid', $profileId)
        ->execute();

        $this->em->remove($member);
        $this->em->flush();

        $this->addFlash('success', 'Membre supprimé.');

        return $this->redirectToRoute('school_members_list', ['type' => $type]);
    }

    #[Route('/{type}/create', name: 'school_members_create', methods: ['GET', 'POST'],
        requirements: ['type' => 'students|teachers|admins'])]
    public function create(Request $request, string $type): Response
    {
        /** @var User $user */
        $user       = $this->getUser();
        $school     = $this->schoolContext->getCurrentSchool();
        $schoolMember = $this->schoolContext->getCurrentSchoolMember($user);

        if ($school === null || $schoolMember === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::UPDATE, $school);

        $roleMap = [
            'students' => SchoolRole::Student,
            'teachers' => SchoolRole::Teacher,
            'admins'   => SchoolRole::School,
        ];

        $error      = null;
        $emailValue = '';
        $noteValue  = '';

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('create_member', (string) $request->request->get('_token'))) {
                $error = 'Token CSRF invalide.';
            } else {
                $memberEmail = trim((string) $request->request->get('email', ''));
                $noteValue   = trim((string) $request->request->get('note', ''));
                $emailValue  = $memberEmail;

                if ($memberEmail === '' || !filter_var($memberEmail, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Veuillez saisir une adresse e-mail valide.';
                } else {
                    // Season required for all member types
                    $seasonId = $school->getCurrentSeasonId();
                    $season   = $seasonId ? $this->em->getRepository(Season::class)->find($seasonId) : null;

                    if ($season === null) {
                        $error = 'Aucune saison active trouvée. Créez d\'abord une saison.';
                    } else {
                        $userAccount = $this->em->getRepository(User::class)->findOneBy(['email' => $memberEmail]);

                        if ($userAccount !== null && $userAccount->getProfile() !== null) {
                            $existingMember = $this->em->getRepository(SchoolProfileSeason::class)->findOneBy([
                                'profileId' => $userAccount->getProfile()->getId(),
                                'schoolId'  => $school->getId(),
                                'seasonId'  => $season->getId(),
                                'role'      => $roleMap[$type],
                            ]);

                            if ($existingMember !== null) {
                                $roleLabels = ['students' => 'élève', 'teachers' => 'professeur', 'admins' => 'administrateur'];
                                $error = sprintf('Cet e-mail est déjà inscrit comme %s dans cette école pour cette saison.', $roleLabels[$type]);
                            }
                        }

                        if ($error === null) {
                            $isNewAccount = false;
                            if ($userAccount === null) {
                                $userAccount = new User();
                                $userAccount->setEmail($memberEmail);
                                $userAccount->setEmailVerified(true);
                                $tempPassword = bin2hex(random_bytes(16));
                                $userAccount->setPasswordHash($this->passwordHasher->hashPassword($userAccount, $tempPassword));
                                $resetToken = Uuid::v4()->toRfc4122();
                                $userAccount->setResetToken($resetToken);
                                $userAccount->setResetTokenExpiresAt(new \DateTimeImmutable('+30 days'));
                                $this->em->persist($userAccount);
                                $this->em->flush();
                                $isNewAccount = true;
                            }

                            $this->memberService->createMember($school, $season, [
                                'note' => $noteValue ?: null,
                                'role' => $roleMap[$type],
                                'user' => $userAccount,
                            ]);

                            $this->memberService->syncUserRoles($userAccount);
                            $this->em->flush();

                            /** @var User $loggedIn */
                            $loggedIn = $this->getUser();
                            if ($loggedIn !== null && $loggedIn->getId() === $userAccount->getId()) {
                                $newToken = new UsernamePasswordToken($userAccount, 'main', $userAccount->getRoles());
                                $this->tokenStorage->setToken($newToken);
                                $request->getSession()->set('_security_main', serialize($newToken));
                            }

                            try {
                                $this->emailService->sendMemberWelcome($userAccount, $school, $isNewAccount);
                            } catch (\Throwable) {
                            }

                            $this->addFlash('success', 'Membre ajouté avec succès.');

                            return $this->redirectToRoute('school_members_list', ['type' => $type]);
                        }
                    }
                }
            }

            if ($error !== null) {
                $this->addFlash('error', $error);
            }
        }

        return $this->render('school/members/create.html.twig', [
            'school' => $school,
            'type'   => $type,
            'email'  => $emailValue,
            'note'   => $noteValue,
        ]);
    }
}
