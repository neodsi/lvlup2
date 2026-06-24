<?php

declare(strict_types=1);

namespace App\Controller\School;

use App\Entity\Activity;
use App\Entity\Order;
use App\Entity\Package;
use App\Entity\PaymentSchedule;
use App\Entity\PriceModifier;
use App\Entity\Season;
use App\Entity\SchoolProfile;
use App\Entity\SchoolProfilePackage;
use App\Entity\SchoolProfileSeason;
use App\Entity\User;
use App\Enum\Gender;
use App\Enum\PackageStatus;
use App\Enum\RegistrationStatus;
use App\Enum\ScheduleStatus;
use App\Enum\SchoolRole;
use App\Form\School\MemberType;
use App\Form\School\StaffMemberType;
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
    ) {
    }

    #[Route('/{type}', name: 'school_members_list', methods: ['GET'],
        requirements: ['type' => 'all|students|teachers|admins'])]
    public function list(string $type, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

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
        $search             = trim((string) $request->query->get('q', ''));
        $filterStatus       = (string) $request->query->get('registration_status', '');
        $filterHasUser      = (string) $request->query->get('has_user', '');
        $filterHasInjury    = (string) $request->query->get('has_injury', '');
        $filterPayStatus    = (string) $request->query->get('payment_status', '');
        $filterAgeGroup     = '';
        $filterActivity     = (string) $request->query->get('activity', '');
        $filterPkgType      = (string) $request->query->get('package_type', '');
        $filterPackageId    = (string) $request->query->get('package_id', '');
        $filterPayLoc       = (string) $request->query->get('payment_location', '');
        $filterOnsiteMethod = (string) $request->query->get('onsite_method', '');
        $filterOnlineMethod = (string) $request->query->get('online_method', '');
        $filterPastDue      = (string) $request->query->get('past_due', '');

        // Build member query (eager-load profile + user to avoid N+1)
        $qb = $this->em->createQueryBuilder()
            ->select('tp', 'p', 'u')
            ->from(SchoolProfile::class, 'tp')
            ->join('tp.profile', 'p')
            ->leftJoin('p.user', 'u')
            ->where('tp.school = :school')
            ->andWhere('tp.deletedAt IS NULL')
            ->orderBy('p.lastName', 'ASC')
            ->addOrderBy('p.firstName', 'ASC')
            ->setParameter('school', $school);

        if ($type !== 'all') {
            $qb->andWhere('tp.role = :role')->setParameter('role', $roleMap[$type]);
        }

        if ($search !== '') {
            $qb->andWhere('LOWER(p.firstName) LIKE :q OR LOWER(p.lastName) LIKE :q OR p.phone LIKE :q')
               ->setParameter('q', '%' . mb_strtolower($search) . '%');
        }

        if ($filterHasUser === 'yes') {
            $qb->andWhere('u IS NOT NULL');
        } elseif ($filterHasUser === 'no') {
            $qb->andWhere('u IS NULL');
        }

        /** @var SchoolProfile[] $members */
        $members   = $qb->getQuery()->getResult();
        $memberIds = array_map(static fn(SchoolProfile $m): string => $m->getId(), $members);

        // TPS map for the current season
        $tpsMap = [];
        if ($season !== null && count($memberIds) > 0) {
            $tpsList = $this->em->getRepository(SchoolProfileSeason::class)->findBy([
                'seasonId' => $season->getId(),
            ]);
            foreach ($tpsList as $tps) {
                $tpsMap[$tps->getSchoolProfileId()] = $tps;
            }
        }

        // Students must have a TPS entry for the selected season
        if ($type === 'students' && $season !== null) {
            $members = array_values(array_filter(
                $members,
                static fn(SchoolProfile $m): bool => isset($tpsMap[$m->getId()])
            ));
            $memberIds = array_map(static fn(SchoolProfile $m): string => $m->getId(), $members);
        }

        // PHP-side TPS filters (registration status, injury, age group, activity)
        if ($filterStatus !== '' || $filterHasInjury !== '' || $filterAgeGroup !== '' || $filterActivity !== '') {
            $members = array_values(array_filter(
                $members,
                static function (SchoolProfile $m) use ($tpsMap, $filterStatus, $filterHasInjury, $filterAgeGroup, $filterActivity): bool {
                    $tps = $tpsMap[$m->getId()] ?? null;

                    if ($filterStatus !== '' && ($tps === null || $tps->getRegistrationStatus()->value !== $filterStatus)) {
                        return false;
                    }
                    if ($filterHasInjury === 'yes' && ($tps === null || !$tps->getInjuryWarning())) {
                        return false;
                    }
                    if ($filterHasInjury === 'no' && ($tps !== null && $tps->getInjuryWarning())) {
                        return false;
                    }
                    if ($filterAgeGroup !== '' && ($tps === null || $tps->getAgeGroupId() !== $filterAgeGroup)) {
                        return false;
                    }
                    if ($filterActivity !== '' && ($tps === null || !in_array($filterActivity, $tps->getActivityIds() ?? [], true))) {
                        return false;
                    }

                    return true;
                }
            ));
            $memberIds = array_map(static fn(SchoolProfile $m): string => $m->getId(), $members);
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
        $activitiesMap      = [];
        $allActivities      = [];
        $allReductions      = [];
        $allIncrements      = [];

        if ($season !== null && count($memberIds) > 0) {

            // Order aggregates (total/paid/count per member)
            $orderAgg = $this->em->createQuery(
                'SELECT o.schoolProfileId, SUM(o.totalAmount) as total, SUM(o.paidAmount) as paid, COUNT(o.id) as ordersCount
                 FROM App\Entity\Order o
                 WHERE o.schoolId = :schoolId
                   AND o.seasonId = :seasonId
                   AND o.deletedAt IS NULL
                   AND o.schoolProfileId IN (:ids)
                 GROUP BY o.schoolProfileId'
            )
            ->setParameter('schoolId', $school->getId())
            ->setParameter('seasonId', $season->getId())
            ->setParameter('ids', $memberIds)
            ->getArrayResult();

            foreach ($orderAgg as $row) {
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

                $financialMap[$row['schoolProfileId']] = [
                    'total'       => $total,
                    'paid'        => $paid,
                    'left'        => max(0, $left),
                    'status'      => $payStatus,
                    'ordersCount' => (int) $row['ordersCount'],
                ];
            }

            // Order IDs + buyer profileIds for downstream queries
            $orderIdRows = $this->em->createQuery(
                'SELECT o.id, o.schoolProfileId, o.profileId
                 FROM App\Entity\Order o
                 WHERE o.schoolId = :schoolId
                   AND o.seasonId = :seasonId
                   AND o.deletedAt IS NULL
                   AND o.schoolProfileId IN (:ids)'
            )
            ->setParameter('schoolId', $school->getId())
            ->setParameter('seasonId', $season->getId())
            ->setParameter('ids', $memberIds)
            ->getArrayResult();

            $orderToProfile      = [];
            $orderIds            = [];
            $orderBuyerProfileId = [];
            foreach ($orderIdRows as $row) {
                $orderToProfile[$row['id']]      = $row['schoolProfileId'];
                $orderIds[]                      = $row['id'];
                $orderBuyerProfileId[$row['id']] = $row['profileId'];
            }

            if (count($orderIds) > 0) {
                // Next pending payment schedule per member
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
                    $tpId = $orderToProfile[$row['orderId']] ?? null;
                    if ($tpId === null) {
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
                    if (!isset($nextScheduleMap[$tpId]) || $due < $nextScheduleMap[$tpId]) {
                        $nextScheduleMap[$tpId] = $due;
                    }
                }

                // Total nb payment schedules per member
                $schedCountRows = $this->em->createQuery(
                    'SELECT ps.orderId, COUNT(ps.id) as cnt
                     FROM App\Entity\PaymentSchedule ps
                     WHERE ps.orderId IN (:orderIds)
                     GROUP BY ps.orderId'
                )
                ->setParameter('orderIds', $orderIds)
                ->getArrayResult();

                foreach ($schedCountRows as $row) {
                    $tpId = $orderToProfile[$row['orderId']] ?? null;
                    if ($tpId === null) {
                        continue;
                    }
                    $nbSchedulesMap[$tpId] = ($nbSchedulesMap[$tpId] ?? 0) + (int) $row['cnt'];
                }

                // Last payment date per member
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
                    $tpId = $orderToProfile[$row['orderId']] ?? null;
                    if ($tpId === null) {
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
                    if (!isset($lastPaymentDateMap[$tpId]) || $lastPaid > $lastPaymentDateMap[$tpId]) {
                        $lastPaymentDateMap[$tpId] = $lastPaid;
                    }
                }

                // Payment methods used per member
                $pmRows = $this->em->createQuery(
                    'SELECT py.orderId, py.method
                     FROM App\Entity\Payment py
                     WHERE py.orderId IN (:orderIds)
                       AND py.paidAt IS NOT NULL'
                )
                ->setParameter('orderIds', $orderIds)
                ->getArrayResult();

                foreach ($pmRows as $row) {
                    $tpId = $orderToProfile[$row['orderId']] ?? null;
                    if ($tpId === null) {
                        continue;
                    }
                    $m   = $row['method'];
                    $val = $m instanceof \UnitEnum ? $m->value : (string) $m;
                    if (!isset($paymentMethodMap[$tpId]) || !in_array($val, $paymentMethodMap[$tpId], true)) {
                        $paymentMethodMap[$tpId][] = $val;
                    }
                }

                // Buyer names — profile on the order when it differs from the member's own profile
                $memberProfileIdMap = [];
                foreach ($members as $m) {
                    if ($m->getProfile() !== null) {
                        $memberProfileIdMap[$m->getId()] = $m->getProfile()->getId();
                    }
                }

                $externalBuyerPids = [];
                $memberBuyerPids   = [];
                foreach ($orderBuyerProfileId as $ordId => $buyerPid) {
                    $tpId      = $orderToProfile[$ordId] ?? null;
                    $memberPid = $memberProfileIdMap[$tpId ?? ''] ?? null;
                    if ($tpId !== null && $buyerPid !== $memberPid) {
                        $externalBuyerPids[$buyerPid]      = true;
                        $memberBuyerPids[$tpId][$buyerPid] = true;
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

                    foreach ($memberBuyerPids as $tpId => $pids) {
                        $names = array_values(array_filter(
                            array_map(static fn(string $pid): ?string => $buyerNames[$pid] ?? null, array_keys($pids))
                        ));
                        if (!empty($names)) {
                            $buyerMap[$tpId] = implode(', ', $names);
                        }
                    }
                }
            }

            // Active packages count per member
            $pkgRows = $this->em->createQuery(
                'SELECT pkg.schoolProfileId, COUNT(pkg.id) as cnt
                 FROM App\Entity\SchoolProfilePackage pkg
                 WHERE pkg.schoolId = :schoolId
                   AND pkg.seasonId = :seasonId
                   AND pkg.schoolProfileId IN (:ids)
                   AND pkg.deletedAt IS NULL
                   AND pkg.status = :status
                 GROUP BY pkg.schoolProfileId'
            )
            ->setParameter('schoolId', $school->getId())
            ->setParameter('seasonId', $season->getId())
            ->setParameter('ids', $memberIds)
            ->setParameter('status', PackageStatus::Active)
            ->getArrayResult();

            foreach ($pkgRows as $row) {
                $packagesMap[$row['schoolProfileId']] = (int) $row['cnt'];
            }

            // Package types per member
            $pkgTypeRows = $this->em->createQuery(
                'SELECT pkg.schoolProfileId, pkg.type
                 FROM App\Entity\SchoolProfilePackage pkg
                 WHERE pkg.schoolId = :schoolId
                   AND pkg.seasonId = :seasonId
                   AND pkg.schoolProfileId IN (:ids)
                   AND pkg.deletedAt IS NULL'
            )
            ->setParameter('schoolId', $school->getId())
            ->setParameter('seasonId', $season->getId())
            ->setParameter('ids', $memberIds)
            ->getArrayResult();

            $seenPkgTypes = [];
            foreach ($pkgTypeRows as $row) {
                $pkgTypesMap[$row['schoolProfileId']][] = $row['type'];
                $seenPkgTypes[$row['type']]            = true;
            }
            $usedPkgTypes = array_keys($seenPkgTypes);

            // Payment methods split by location
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
                static function (SchoolProfile $m) use ($financialMap, $filterPayStatus): bool {
                    $fin = $financialMap[$m->getId()] ?? null;

                    return $fin === null ? $filterPayStatus === 'unpaid' : $fin['status'] === $filterPayStatus;
                }
            ));
        }

        if ($filterPayLoc !== '') {
            $members = array_values(array_filter(
                $members,
                static function (SchoolProfile $m) use ($paymentMethodMap, $filterPayLoc): bool {
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
                static function (SchoolProfile $m) use ($pkgTypesMap, $filterPkgType): bool {
                    return in_array($filterPkgType, $pkgTypesMap[$m->getId()] ?? [], true);
                }
            ));
        }

        if ($filterPastDue === 'yes') {
            $members = array_values(array_filter(
                $members,
                static function (SchoolProfile $m) use ($nextScheduleMap, $now): bool {
                    $due = $nextScheduleMap[$m->getId()] ?? null;

                    return $due instanceof \DateTimeImmutable && $due < $now;
                }
            ));
        }

        if ($filterOnsiteMethod !== '') {
            $members = array_values(array_filter(
                $members,
                static function (SchoolProfile $m) use ($paymentMethodMap, $filterOnsiteMethod): bool {
                    return in_array($filterOnsiteMethod, $paymentMethodMap[$m->getId()] ?? [], true);
                }
            ));
        }

        if ($filterOnlineMethod !== '') {
            $members = array_values(array_filter(
                $members,
                static function (SchoolProfile $m) use ($paymentMethodMap, $filterOnlineMethod): bool {
                    return in_array($filterOnlineMethod, $paymentMethodMap[$m->getId()] ?? [], true);
                }
            ));
        }

        if ($filterPackageId !== '' && $season !== null) {
            $pkgMemberIds = array_column(
                $this->em->createQuery(
                    'SELECT tpp.schoolProfileId FROM App\Entity\SchoolProfilePackage tpp
                     WHERE tpp.packageId = :pkgId AND tpp.seasonId = :seasonId AND tpp.deletedAt IS NULL'
                )
                ->setParameter('pkgId', $filterPackageId)
                ->setParameter('seasonId', $season->getId())
                ->getArrayResult(),
                'schoolProfileId'
            );
            $members = array_values(array_filter(
                $members,
                static function (SchoolProfile $m) use ($pkgMemberIds): bool {
                    return in_array($m->getId(), $pkgMemberIds, true);
                }
            ));
        }

        // Activities, age groups & price modifiers for the season
        if ($season !== null) {
            $actList = $this->em->createQuery(
                'SELECT a FROM App\Entity\Activity a
                 WHERE a.schoolId = :schoolId AND a.seasonId = :seasonId AND a.deletedAt IS NULL
                 ORDER BY a.name ASC'
            )
            ->setParameter('schoolId', $school->getId())
            ->setParameter('seasonId', $season->getId())
            ->getResult();

            foreach ($actList as $act) {
                $activitiesMap[$act->getId()] = $act->getName();
            }
            $allActivities = $actList;

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
            $search, $filterStatus, $filterHasUser, $filterHasInjury,
            $filterPayStatus, $filterAgeGroup, $filterActivity, $filterPkgType,
            $filterPayLoc, $filterPastDue, $filterPackageId,
            $filterOnsiteMethod, $filterOnlineMethod,
        ]));

        return $this->render('school/members/list.html.twig', [
            'school'                => $school,
            'type'                => $type,
            'members'             => $members,
            'season'              => $season,
            'tpsMap'              => $tpsMap,
            'financialMap'        => $financialMap,
            'nextScheduleMap'     => $nextScheduleMap,
            'nbSchedulesMap'      => $nbSchedulesMap,
            'lastPaymentDateMap'  => $lastPaymentDateMap,
            'buyerMap'            => $buyerMap,
            'packagesMap'         => $packagesMap,
            'paymentMethodMap'    => $paymentMethodMap,
            'pkgTypesMap'         => $pkgTypesMap,
            'usedPkgTypes'        => $usedPkgTypes,
            'usedOnsiteMethods'   => $usedOnsiteMethods,
            'usedOnlineMethods'   => $usedOnlineMethods,
            'allPackages'         => $allPackages,
            'activitiesMap'       => $activitiesMap,
            'allActivities'       => $allActivities,
            'allReductions'       => $allReductions,
            'allIncrements'       => $allIncrements,
            'filters'             => [
                'q'                   => $search,
                'registration_status' => $filterStatus,
                'has_user'            => $filterHasUser,
                'has_injury'          => $filterHasInjury,
                'payment_status'      => $filterPayStatus,
                'activity'            => $filterActivity,
                'package_type'        => $filterPkgType,
                'payment_location'    => $filterPayLoc,
                'past_due'            => $filterPastDue,
                'package_id'          => $filterPackageId,
                'onsite_method'       => $filterOnsiteMethod,
                'online_method'       => $filterOnlineMethod,
            ],
            'activeFilterCount'   => $activeFilterCount,
        ]);
    }

    #[Route('/detail/{id}', name: 'school_member_detail', methods: ['GET'])]
    public function detail(string $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

        $member = $this->em->getRepository(SchoolProfile::class)->find($id);

        if ($member === null || $member->getSchool()?->getId() !== $school->getId() || $member->getDeletedAt() !== null) {
            throw $this->createNotFoundException('Member not found.');
        }

        // Fetch TPS for current season
        $seasonId = $school->getCurrentSeasonId();
        $season   = $seasonId ? $this->em->getRepository(Season::class)->find($seasonId) : null;
        $tps      = null;
        if ($season !== null) {
            $tps = $this->em->getRepository(SchoolProfileSeason::class)->findOneBy([
                'schoolProfileId' => $member->getId(),
                'seasonId'      => $season->getId(),
            ]);
        }

        $profile = $member->getProfile();
        $emergencyContact = $tps?->getEmergencyContact();
        $initialData = [
            'firstName'          => $profile?->getFirstName(),
            'lastName'           => $profile?->getLastName(),
            'dob'                => $profile?->getDob(),
            'phone'              => $profile?->getPhone(),
            'addressText'        => $profile?->getAddressText(),
            'gender'             => $profile?->getGender()?->value,
            'note'               => $member->getNote(),
            'registrationStatus' => $tps?->getRegistrationStatus()?->value,
            'injuryWarning'      => $tps?->getInjuryWarning(),
        ];

        $form = $this->createForm(MemberType::class, $initialData);
        $form->get('email')->setData($profile?->getUser()?->getEmail());
        $form->get('emergencyName')->setData($emergencyContact['name'] ?? null);
        $form->get('emergencyRelationship')->setData($emergencyContact['relationship'] ?? null);
        $form->get('emergencyEmail')->setData($emergencyContact['email'] ?? null);
        $form->get('emergencyPhone')->setData($emergencyContact['phone'] ?? null);
        $form->get('accepted')->setData($tps?->getAccepted() ?? []);

        return $this->render('school/members/detail.html.twig', [
            'school'   => $school,
            'member'   => $member,
            'season'   => $season,
            'tps'      => $tps,
            'form'     => $form->createView(),
        ]);
    }

    #[Route('/detail/{id}/edit', name: 'school_member_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::UPDATE, $school);

        $member = $this->em->getRepository(SchoolProfile::class)->find($id);

        if ($member === null || $member->getSchool()?->getId() !== $school->getId() || $member->getDeletedAt() !== null) {
            throw $this->createNotFoundException('Member not found.');
        }

        $profile = $member->getProfile();

        // Fetch TPS for current season
        $seasonId = $school->getCurrentSeasonId();
        $season   = $seasonId ? $this->em->getRepository(Season::class)->find($seasonId) : null;
        $tps      = null;
        if ($season !== null) {
            $tps = $this->em->getRepository(SchoolProfileSeason::class)->findOneBy([
                'schoolProfileId' => $member->getId(),
                'seasonId'      => $season->getId(),
            ]);
        }

        // Build initial data from existing member
        $emergencyContact = $tps?->getEmergencyContact();
        $initialData = [
            'firstName'          => $profile?->getFirstName(),
            'lastName'           => $profile?->getLastName(),
            'dob'                => $profile?->getDob(),
            'phone'              => $profile?->getPhone(),
            'addressText'        => $profile?->getAddressText(),
            'gender'             => $profile?->getGender()?->value,
            'note'               => $member->getNote(),
            'registrationStatus' => $tps?->getRegistrationStatus()?->value,
            'injuryWarning'      => $tps?->getInjuryWarning(),
        ];

        $form = $this->createForm(MemberType::class, $initialData);

        // Pre-fill unmapped fields from existing data
        $form->get('email')->setData($profile?->getUser()?->getEmail());
        $form->get('emergencyName')->setData($emergencyContact['name'] ?? null);
        $form->get('emergencyRelationship')->setData($emergencyContact['relationship'] ?? null);
        $form->get('emergencyEmail')->setData($emergencyContact['email'] ?? null);
        $form->get('emergencyPhone')->setData($emergencyContact['phone'] ?? null);
        $form->get('accepted')->setData($tps?->getAccepted() ?? []);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            // Update Profile
            if ($profile !== null) {
                $profile->setFirstName((string) ($data['firstName'] ?? ''));
                $profile->setLastName((string) ($data['lastName'] ?? ''));
                $profile->setPhone($data['phone'] ?: null);
                $profile->setAddressText($data['addressText'] ?: null);

                $genderVal = $data['gender'] ?? null;
                $profile->setGender($genderVal ? Gender::from($genderVal) : null);

                $dobVal = $data['dob'] ?? null;
                if ($dobVal instanceof \DateTimeInterface) {
                    $profile->setDob(\DateTimeImmutable::createFromInterface($dobVal));
                } else {
                    $profile->setDob(null);
                }
            }

            // Update note
            $member->setNote($data['note'] ?: null);

            // Update or create SchoolProfileSeason
            if ($season !== null) {
                if ($tps === null) {
                    $tps = new SchoolProfileSeason();
                    $tps->setSchoolProfileId($member->getId());
                    $tps->setSeasonId($season->getId());
                    $tps->setSchoolId($school->getId());
                    $this->em->persist($tps);
                }

                $regStatus = $data['registrationStatus'] ?? null;
                if ($regStatus) {
                    $tps->setRegistrationStatus(RegistrationStatus::from($regStatus));
                }
                $tps->setInjuryWarning($data['injuryWarning'] ?: null);

                $accepted = $form->get('accepted')->getData();
                $tps->setAccepted($accepted ?: null);

                $ecName = $form->get('emergencyName')->getData();
                $ecPhone = $form->get('emergencyPhone')->getData();
                if ($ecName || $ecPhone) {
                    $tps->setEmergencyContact([
                        'name'         => $form->get('emergencyName')->getData() ?? '',
                        'relationship' => $form->get('emergencyRelationship')->getData() ?? '',
                        'email'        => $form->get('emergencyEmail')->getData() ?? '',
                        'phone'        => $form->get('emergencyPhone')->getData() ?? '',
                    ]);
                }
            }

            $this->em->flush();
            $this->addFlash('success', 'Fiche mise à jour.');

            return $this->redirectToRoute('school_member_detail', ['id' => $id]);
        }

        return $this->render('school/members/detail.html.twig', [
            'school'   => $school,
            'member' => $member,
            'season' => $season,
            'tps'    => $tps,
            'form'   => $form->createView(),
        ]);
    }

    #[Route('/detail/{id}/delete', name: 'school_member_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::UPDATE, $school);

        if (!$this->isCsrfTokenValid('delete_member_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $member = $this->em->getRepository(SchoolProfile::class)->find($id);

        if ($member === null || $member->getSchool()?->getId() !== $school->getId() || $member->getDeletedAt() !== null) {
            throw $this->createNotFoundException('Member not found.');
        }

        $type = match ($member->getRole()) {
            \App\Enum\SchoolRole::Teacher => 'teachers',
            \App\Enum\SchoolRole::School   => 'admins',
            default                         => 'students',
        };

        $memberId = $member->getId();

        $this->em->createQuery('DELETE FROM App\Entity\SchoolProfileSeason tps WHERE tps.schoolProfileId = :id')
            ->setParameter('id', $memberId)->execute();
        $this->em->createQuery('DELETE FROM App\Entity\SchoolProfilePackage tpp WHERE tpp.schoolProfileId = :id')
            ->setParameter('id', $memberId)->execute();
        $this->em->createQuery('DELETE FROM App\Entity\EventOccurenceProfile eop WHERE eop.schoolProfileId = :id')
            ->setParameter('id', $memberId)->execute();
        $this->em->createQuery('DELETE FROM App\Entity\SchoolProfileGalaParticipation tpgp WHERE tpgp.schoolProfileId = :id')
            ->setParameter('id', $memberId)->execute();

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
        $user        = $this->getUser();
        $school        = $this->schoolContext->getCurrentSchool();
        $schoolProfile = $this->schoolContext->getCurrentSchoolProfile($user);

        if ($school === null || $schoolProfile === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::UPDATE, $school);

        $isStudent = $type === 'students';
        $form = $this->createForm($isStudent ? MemberType::class : StaffMemberType::class, []);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $roleMap = [
                'students' => SchoolRole::Student,
                'teachers' => SchoolRole::Teacher,
                'admins'   => SchoolRole::School,
            ];

            $season = null;
            if ($isStudent) {
                $seasonId = $school->getCurrentSeasonId();
                $season   = $seasonId ? $this->em->getRepository(Season::class)->find($seasonId) : null;

                if ($season === null) {
                    throw $this->createNotFoundException('No active season found.');
                }
            }

            $data = $form->getData();

            // Resolve Gender enum from string value
            $genderVal = $data['gender'] ?? null;
            $gender    = $genderVal ? Gender::tryFrom($genderVal) : null;

            // Resolve dob: DateType returns a DateTimeInterface or null
            $dobVal = $data['dob'] ?? null;
            $dob    = null;
            if ($dobVal instanceof \DateTimeInterface) {
                $dob = \DateTimeImmutable::createFromInterface($dobVal);
            }

            // Unmapped fields
            $memberEmail = trim((string) ($form->get('email')->getData() ?? ''));

            // Student-only season fields
            $regStatusVal    = null;
            $emergencyContact = null;
            $accepted        = [];
            if ($isStudent) {
                $regStatus    = $data['registrationStatus'] ?? null;
                $regStatusVal = $regStatus ? RegistrationStatus::tryFrom($regStatus) : null;

                $accepted = $form->get('accepted')->getData() ?? [];

                $ecName  = $form->get('emergencyName')->getData();
                $ecPhone = $form->get('emergencyPhone')->getData();
                if ($ecName || $ecPhone) {
                    $emergencyContact = [
                        'name'         => $form->get('emergencyName')->getData() ?? '',
                        'relationship' => $form->get('emergencyRelationship')->getData() ?? '',
                        'email'        => $form->get('emergencyEmail')->getData() ?? '',
                        'phone'        => $form->get('emergencyPhone')->getData() ?? '',
                    ];
                }
            }

            // Create or reuse User account
            $userRoleMap = [
                'students' => 'ROLE_STUDENT',
                'teachers' => 'ROLE_TEACHER',
                'admins'   => 'ROLE_SCHOOL',
            ];

            $userAccount  = $this->em->getRepository(User::class)->findOneBy(['email' => $memberEmail]);
            $isNewAccount = false;

            // Block only if same email + same role already exists in this school
            if ($userAccount !== null) {
                $existingMember = $this->em->createQueryBuilder()
                    ->select('COUNT(tp.id)')
                    ->from(SchoolProfile::class, 'tp')
                    ->join('tp.profile', 'p')
                    ->where('tp.school = :school')
                    ->andWhere('p.user = :user')
                    ->andWhere('tp.role = :role')
                    ->andWhere('tp.deletedAt IS NULL')
                    ->setParameter('school', $school)
                    ->setParameter('user', $userAccount)
                    ->setParameter('role', $roleMap[$type])
                    ->getQuery()
                    ->getSingleScalarResult();

                if ((int) $existingMember > 0) {
                    $roleLabels = [
                        'students' => 'élève',
                        'teachers' => 'professeur',
                        'admins'   => 'administrateur',
                    ];
                    $this->addFlash('error', sprintf(
                        'Cet e-mail est déjà inscrit comme %s dans cette école.',
                        $roleLabels[$type]
                    ));

                    return $this->render('school/members/create.html.twig', [
                        'school' => $school,
                        'type' => $type,
                        'form' => $form->createView(),
                    ]);
                }
            }

            if ($userAccount === null) {
                $userAccount = new User();
                $userAccount->setEmail($memberEmail);
                $userAccount->setRoles([$userRoleMap[$type]]);
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
                'firstName'          => (string) ($data['firstName'] ?? ''),
                'lastName'           => (string) ($data['lastName'] ?? ''),
                'dob'                => $dob,
                'phone'              => $data['phone'] ?: null,
                'gender'             => $gender,
                'addressText'        => $data['addressText'] ?: null,
                'note'               => $data['note'] ?: null,
                'registrationStatus' => $regStatusVal,
                'injuryWarning'      => $isStudent ? ($data['injuryWarning'] ?: null) : null,
                'emergencyContact'   => $emergencyContact,
                'accepted'           => $accepted ?: null,
                'role'               => $roleMap[$type],
                'user'               => $userAccount,
            ]);

            // Recalculate users.roles from all active school memberships
            $this->memberService->syncUserRoles($userAccount);
            $this->em->flush();

            try {
                $this->emailService->sendMemberWelcome($userAccount, $school, $isNewAccount);
            } catch (\Throwable) {
                // Email failure is non-blocking
            }

            $this->addFlash('success', 'Membre ajouté avec succès.');

            return $this->redirectToRoute('school_members_list', ['type' => $type]);
        }

        return $this->render('school/members/create.html.twig', [
            'school' => $school,
            'type' => $type,
            'form' => $form->createView(),
        ]);
    }
}
