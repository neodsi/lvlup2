<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Activity;
use App\Entity\Address;
use App\Entity\Event;
use App\Entity\EventOccurence;
use App\Entity\EventOccurenceProfile;
use App\Entity\IntentOrder;
use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Package;
use App\Entity\Payment;
use App\Entity\PaymentSchedule;
use App\Entity\PaymentScheduleTemplate;
use App\Entity\PriceModifier;
use App\Entity\Profile;
use App\Entity\Room;
use App\Entity\School;
use App\Entity\SchoolHomeKpiDaily;
use App\Entity\SchoolProfileGalaParticipation;
use App\Entity\SchoolProfilePackage;
use App\Entity\SchoolProfileSeason;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SchoolStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin', name: 'app_admin')]
    #[IsGranted('ROLE_ADMIN')]
    public function dashboard(): Response
    {
        $totalSchools = $this->em->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(School::class, 't')
            ->getQuery()
            ->getSingleScalarResult();

        $totalUsers = $this->em->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where('u.deletedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        $schoolsByStatus = $this->em->createQueryBuilder()
            ->select('t.status, COUNT(t.id) AS cnt')
            ->from(School::class, 't')
            ->groupBy('t.status')
            ->getQuery()
            ->getResult();

        return $this->render('admin/dashboard.html.twig', [
            'totalSchools'    => (int) $totalSchools,
            'totalUsers'      => (int) $totalUsers,
            'schoolsByStatus' => $schoolsByStatus,
        ]);
    }

    #[Route('/admin/schools', name: 'app_admin_schools')]
    #[IsGranted('ROLE_ADMIN')]
    public function schools(Request $request): Response
    {
        $statusFilter = $request->query->get('status');

        $qb = $this->em->createQueryBuilder()
            ->select('t')
            ->from(School::class, 't')
            ->orderBy('t.createdAt', 'DESC');

        if ($statusFilter !== null && $statusFilter !== '') {
            $status = SchoolStatus::tryFrom($statusFilter);
            if ($status !== null) {
                $qb->where('t.status = :status')
                   ->setParameter('status', $status);
            }
        }

        $schools = $qb->getQuery()->getResult();

        // Member counts per school via school_profile_seasons
        $memberCounts = [];
        $ownersBySchool = [];

        if (!empty($schools)) {
            $schoolIds = array_map(static fn(School $s) => $s->getId(), $schools);
            $in        = implode(',', array_fill(0, count($schoolIds), '?'));
            $rows      = $this->em->getConnection()->executeQuery(
                "SELECT school_id, role, COUNT(*) AS cnt
                 FROM school_profile_seasons
                 WHERE school_id IN ({$in})
                 GROUP BY school_id, role",
                $schoolIds
            )->fetchAllAssociative();

            foreach ($rows as $row) {
                $memberCounts[$row['school_id']][$row['role']] = (int) $row['cnt'];
            }

            // Owner profiles (from ownerProfileId on School)
            $ownerProfileIds = [];
            foreach ($schools as $school) {
                if ($school->getOwnerProfileId() !== null) {
                    $ownerProfileIds[$school->getId()] = $school->getOwnerProfileId();
                }
            }
            if (!empty($ownerProfileIds)) {
                $profileList = $this->em->createQuery(
                    'SELECT p FROM App\Entity\Profile p WHERE p.id IN (:ids)'
                )->setParameter('ids', array_values($ownerProfileIds))->getResult();
                $profilesById = [];
                foreach ($profileList as $p) {
                    $profilesById[$p->getId()] = $p;
                }
                foreach ($ownerProfileIds as $schoolId => $profileId) {
                    $ownersBySchool[$schoolId] = $profilesById[$profileId] ?? null;
                }
            }
        }

        return $this->render('admin/schools/index.html.twig', [
            'schools'        => $schools,
            'memberCounts'   => $memberCounts,
            'ownersBySchool' => $ownersBySchool,
            'statusFilter'   => $statusFilter,
            'statuses'       => SchoolStatus::cases(),
        ]);
    }

    #[Route('/admin/schools/{id}', name: 'app_admin_school_detail', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function schoolDetail(string $id): Response
    {
        $school = $this->em->getRepository(School::class)->find($id);

        if ($school === null) {
            throw $this->createNotFoundException('School not found.');
        }

        // Owner profile
        $ownerProfile = null;
        if ($school->getOwnerProfileId() !== null) {
            $ownerProfile = $this->em->getRepository(Profile::class)->find($school->getOwnerProfileId());
        }

        // Distinct members with their most recent role, across all seasons
        $spsRows = $this->em->getConnection()->executeQuery(
            "SELECT sps.profile_id, sps.role, sps.season_id, s.name AS season_name
             FROM school_profile_seasons sps
             LEFT JOIN seasons s ON s.id = sps.season_id
             WHERE sps.school_id = :schoolId
             ORDER BY sps.created_at DESC",
            ['schoolId' => $id]
        )->fetchAllAssociative();

        // Group by profile_id — collect distinct roles per profile
        $byProfile = [];
        foreach ($spsRows as $row) {
            $pid  = $row['profile_id'];
            $role = $row['role'];
            if (!isset($byProfile[$pid])) {
                $byProfile[$pid] = ['roles' => [], 'seasonName' => $row['season_name'] ?? null];
            }
            if (!in_array($role, $byProfile[$pid]['roles'], true)) {
                $byProfile[$pid]['roles'][] = $role;
            }
        }

        // Load profiles
        $memberProfileIds = array_keys($byProfile);
        $profilesById = [];
        if (!empty($memberProfileIds)) {
            $profileList = $this->em->createQuery(
                'SELECT p FROM App\Entity\Profile p WHERE p.id IN (:ids)'
            )->setParameter('ids', $memberProfileIds)->getResult();
            foreach ($profileList as $p) {
                $profilesById[$p->getId()] = $p;
            }
        }

        $members = [];
        foreach ($byProfile as $profileId => $data) {
            $members[] = [
                'profile'    => $profilesById[$profileId] ?? null,
                'roles'      => $data['roles'],
                'seasonName' => $data['seasonName'],
                'isOwner'    => $school->getOwnerProfileId() === $profileId,
            ];
        }

        // Owner with no SPS at all (no season created yet)
        if ($ownerProfile !== null && !isset($byProfile[$ownerProfile->getId()])) {
            array_unshift($members, [
                'profile'    => $ownerProfile,
                'roles'      => ['school'],
                'seasonName' => null,
                'isOwner'    => true,
            ]);
        }

        usort($members, static fn($a, $b) => (int) $b['isOwner'] - (int) $a['isOwner']);

        return $this->render('admin/schools/detail.html.twig', [
            'school'       => $school,
            'statuses'     => SchoolStatus::cases(),
            'ownerProfile' => $ownerProfile,
            'members'      => $members,
            'canDelete'    => empty($spsProfileIds) && $ownerProfile === null,
        ]);
    }

    #[Route('/admin/schools/{id}/status', name: 'app_admin_school_status', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function setSchoolStatus(string $id, Request $request): Response
    {
        $school = $this->em->getRepository(School::class)->find($id);

        if ($school === null) {
            throw $this->createNotFoundException('School not found.');
        }

        if (!$this->isCsrfTokenValid('school_status_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $newStatus = SchoolStatus::tryFrom((string) $request->request->get('status'));
        if ($newStatus !== null) {
            $school->setStatus($newStatus);
            $this->em->flush();
            $this->addFlash('success', 'Statut mis à jour.');
        }

        return $this->redirectToRoute('app_admin_school_detail', ['id' => $id]);
    }

    #[Route('/admin/schools/{id}/delete', name: 'app_admin_school_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteSchool(string $id, Request $request): Response
    {
        $school = $this->em->getRepository(School::class)->find($id);

        if ($school === null) {
            throw $this->createNotFoundException('School not found.');
        }

        if (!$this->isCsrfTokenValid('delete_school_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $hasPayments = (bool) $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Payment::class, 'p')
            ->where('p.schoolId = :sid')
            ->setParameter('sid', $id)
            ->getQuery()
            ->getSingleScalarResult();

        if ($hasPayments) {
            $school->setDeletedAt(new \DateTimeImmutable());
            $this->em->flush();
            $this->addFlash('success', 'École archivée (paiements conservés).');
            return $this->redirectToRoute('app_admin_schools');
        }

        $orderIds = array_column(
            $this->em->createQueryBuilder()
                ->select('o.id')
                ->from(Order::class, 'o')
                ->where('o.schoolId = :sid')
                ->setParameter('sid', $id)
                ->getQuery()
                ->getArrayResult(),
            'id'
        );

        if ($orderIds) {
            $this->em->createQueryBuilder()
                ->delete(OrderItem::class, 'e')
                ->where('e.orderId IN (:ids)')
                ->setParameter('ids', $orderIds)
                ->getQuery()
                ->execute();
        }

        foreach ([
            SchoolHomeKpiDaily::class,
            SchoolProfileGalaParticipation::class,
            SchoolProfileSeason::class,
            SchoolProfilePackage::class,
            IntentOrder::class,
            EventOccurenceProfile::class,
            PaymentSchedule::class,
            Invoice::class,
            Order::class,
            EventOccurence::class,
            Event::class,
            PriceModifier::class,
            PaymentScheduleTemplate::class,
            Room::class,
            Activity::class,
            Package::class,
            Address::class,
            Season::class,
        ] as $cls) {
            $this->em->createQueryBuilder()
                ->delete($cls, 'e')
                ->where('e.schoolId = :sid')
                ->setParameter('sid', $id)
                ->getQuery()
                ->execute();
        }

        $this->em->remove($school);
        $this->em->flush();

        $this->addFlash('success', 'École et toutes ses données supprimées définitivement.');
        return $this->redirectToRoute('app_admin_schools');
    }

    #[Route('/admin/users', name: 'app_admin_users')]
    #[IsGranted('ROLE_ADMIN')]
    public function users(Request $request): Response
    {
        $search     = $request->query->get('q', '');
        $roleFilter = $request->query->get('role', '');

        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.deletedAt IS NULL')
            ->orderBy('u.createdAt', 'DESC');

        if ($search !== '') {
            $qb->andWhere('u.email LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        if ($roleFilter !== '') {
            $qb->andWhere('u.roles LIKE :role')
               ->setParameter('role', '%' . $roleFilter . '%');
        }

        $users = $qb->getQuery()->getResult();

        // Memberships per user via school_profile_seasons
        $memberships = [];
        if (!empty($users)) {
            $profileIds = [];
            $profileToUser = [];
            foreach ($users as $u) {
                $pid = $u->getProfile()?->getId();
                if ($pid !== null) {
                    $profileIds[]           = $pid;
                    $profileToUser[$pid]    = $u->getId();
                }
            }

            if (!empty($profileIds)) {
                $conn = $this->em->getConnection();
                $in   = implode(',', array_fill(0, count($profileIds), '?'));

                // Schools owned by this user (via ownerProfileId on schools table)
                $ownedRows = $conn->executeQuery(
                    "SELECT id AS schoolId, name AS schoolName, owner_profile_id AS profileId
                     FROM schools
                     WHERE owner_profile_id IN ({$in}) AND deleted_at IS NULL
                     ORDER BY name ASC",
                    $profileIds
                )->fetchAllAssociative();

                $ownedByProfile = [];
                foreach ($ownedRows as $row) {
                    $ownedByProfile[$row['profileId']][$row['schoolId']] = true;
                    $userId = $profileToUser[$row['profileId']] ?? null;
                    if ($userId !== null) {
                        $memberships[$userId][] = [
                            'schoolId'   => $row['schoolId'],
                            'schoolName' => $row['schoolName'],
                            'role'       => 'school',
                            'isOwner'    => true,
                        ];
                    }
                }

                // SPS memberships — one entry per (user, school, role)
                $rows = $conn->executeQuery(
                    "SELECT sps.profile_id AS profileId, s.id AS schoolId, s.name AS schoolName, sps.role AS role
                     FROM school_profile_seasons sps
                     INNER JOIN schools s ON s.id = sps.school_id
                     WHERE sps.profile_id IN ({$in})
                     ORDER BY s.name ASC",
                    $profileIds
                )->fetchAllAssociative();

                // Deduplicate by (userId, schoolId, role) and skip role=school when already shown as owner
                $seenSps = [];
                foreach ($rows as $row) {
                    $userId = $profileToUser[$row['profileId']] ?? null;
                    if ($userId === null) {
                        continue;
                    }
                    $isOwner = isset($ownedByProfile[$row['profileId']][$row['schoolId']]);
                    // Skip role=school for owned schools (already shown as Owner entry)
                    if ($isOwner && $row['role'] === 'school') {
                        continue;
                    }
                    $dedupeKey = $userId . '|' . $row['schoolId'] . '|' . $row['role'];
                    if (isset($seenSps[$dedupeKey])) {
                        continue;
                    }
                    $seenSps[$dedupeKey] = true;
                    $memberships[$userId][] = [
                        'schoolId'   => $row['schoolId'],
                        'schoolName' => $row['schoolName'],
                        'role'       => $row['role'],
                        'isOwner'    => false,
                    ];
                }
            }
        }

        return $this->render('admin/users/index.html.twig', [
            'users'       => $users,
            'memberships' => $memberships,
            'search'      => $search,
            'roleFilter'  => $roleFilter,
        ]);
    }

    #[Route('/admin/users/{userId}', name: 'app_admin_user_detail', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function userDetail(string $userId): Response
    {
        $user = $this->em->getRepository(User::class)->find($userId);

        if ($user === null || $user->getDeletedAt() !== null) {
            throw $this->createNotFoundException('Utilisateur introuvable.');
        }

        $profile        = $user->getProfile();
        $schoolProfiles = [];

        if ($profile !== null) {
            $schoolProfiles = $this->em->createQueryBuilder()
                ->select('tps')
                ->from(SchoolProfileSeason::class, 'tps')
                ->where('tps.profileId = :profileId')
                ->orderBy('tps.createdAt', 'DESC')
                ->setParameter('profileId', $profile->getId())
                ->getQuery()
                ->getResult();
        }

        return $this->render('admin/users/detail.html.twig', [
            'user'           => $user,
            'schoolProfiles' => $schoolProfiles,
        ]);
    }

    #[Route('/admin/users/{userId}/delete', name: 'app_admin_user_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteUser(string $userId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_user_' . $userId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $this->em->getRepository(User::class)->find($userId);

        if ($user === null || $user->getDeletedAt() !== null) {
            throw $this->createNotFoundException('Utilisateur introuvable.');
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if ($user->getId() === $currentUser->getId()) {
            $this->addFlash('error', 'Vous ne pouvez pas supprimer votre propre compte.');
            return $this->redirectToRoute('app_admin_users');
        }

        $profile = $user->getProfile();

        if ($profile !== null) {
            $profileId = $profile->getId();

            $this->em->createQuery('DELETE FROM App\Entity\SchoolProfilePackage tpp WHERE tpp.profileId = :id')
                ->setParameter('id', $profileId)->execute();
            $this->em->createQuery('DELETE FROM App\Entity\EventOccurenceProfile eop WHERE eop.profileId = :id')
                ->setParameter('id', $profileId)->execute();
            $this->em->createQuery('DELETE FROM App\Entity\SchoolProfileGalaParticipation tpgp WHERE tpgp.profileId = :id')
                ->setParameter('id', $profileId)->execute();
            $this->em->createQuery('DELETE FROM App\Entity\SchoolProfileSeason tps WHERE tps.profileId = :id')
                ->setParameter('id', $profileId)->execute();
        }

        $this->em->createQuery('DELETE FROM App\Entity\Profile p WHERE p.user = :user')
            ->setParameter('user', $user)
            ->execute();

        $this->em->remove($user);
        $this->em->flush();

        $this->addFlash('success', 'Utilisateur supprimé.');

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/admin/impersonate/{userId}', name: 'app_admin_impersonate')]
    #[IsGranted('ROLE_ADMIN')]
    public function impersonate(string $userId): Response
    {
        $user = $this->em->getRepository(User::class)->find($userId);

        if ($user === null) {
            throw $this->createNotFoundException('Utilisateur introuvable.');
        }

        if ($user->getId() === $this->getUser()?->getUserIdentifier()) {
            throw $this->createAccessDeniedException('Impossible de s\'impersoner soi-même.');
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            throw $this->createAccessDeniedException('Impossible d\'impersoner un administrateur.');
        }

        return $this->redirect('/?_switch_user=' . urlencode($user->getEmail()));
    }

    #[Route('/switch-back', name: 'app_admin_switch_back')]
    public function switchBack(): Response
    {
        return $this->redirect($this->generateUrl('app_admin_users') . '?_switch_user=_exit');
    }
}
