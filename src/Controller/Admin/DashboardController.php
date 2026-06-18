<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Profile;
use App\Entity\School;
use App\Entity\SchoolProfile;
use App\Entity\User;
use App\Enum\SchoolRole;
use App\Enum\SchoolStatus;
use App\Form\Admin\SchoolStatusType;
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
    #[IsGranted('ROLE_SCHOOL')]
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
            'totalSchools'     => (int) $totalSchools,
            'totalUsers'     => (int) $totalUsers,
            'schoolsByStatus'  => $schoolsByStatus,
        ]);
    }

    #[Route('/admin/schools', name: 'app_admin_schools')]
    #[IsGranted('ROLE_SCHOOL')]
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

        return $this->render('admin/schools/index.html.twig', [
            'schools'        => $schools,
            'statusFilter' => $statusFilter,
            'statuses'     => SchoolStatus::cases(),
        ]);
    }

    #[Route('/admin/schools/{id}', name: 'app_admin_school_detail')]
    #[IsGranted('ROLE_SCHOOL')]
    public function schoolDetail(string $id, Request $request): Response
    {
        $school = $this->em->getRepository(School::class)->find($id);

        if ($school === null) {
            throw $this->createNotFoundException('School not found.');
        }

        $form = $this->createForm(SchoolStatusType::class, ['status' => $school->getStatus()->value]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newStatus = SchoolStatus::tryFrom((string) $form->get('status')->getData());

            if ($newStatus !== null) {
                $school->setStatus($newStatus);
                $this->em->flush();

                $this->addFlash('success', 'Statut mis à jour.');
            } else {
                $this->addFlash('error', 'Statut invalide.');
            }

            return $this->redirectToRoute('app_admin_school_detail', ['id' => $id]);
        }

        $ownerProfile = $this->em->createQueryBuilder()
            ->select('tp, p, u')
            ->from(SchoolProfile::class, 'tp')
            ->join('tp.profile', 'p')
            ->join('p.user', 'u')
            ->where('tp.school = :school')
            ->andWhere('tp.role = :role')
            ->andWhere('tp.deletedAt IS NULL')
            ->setParameter('school', $school)
            ->setParameter('role', SchoolRole::Owner)
            ->getQuery()
            ->getOneOrNullResult();

        return $this->render('admin/schools/detail.html.twig', [
            'school'          => $school,
            'statuses'      => SchoolStatus::cases(),
            'ownerProfile'  => $ownerProfile,
            'form'          => $form->createView(),
        ]);
    }

    #[Route('/admin/users', name: 'app_admin_users')]
    #[IsGranted('ROLE_SCHOOL')]
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

        return $this->render('admin/users/index.html.twig', [
            'users'      => $users,
            'search'     => $search,
            'roleFilter' => $roleFilter,
        ]);
    }

    #[Route('/admin/users/{userId}', name: 'app_admin_user_detail', methods: ['GET'])]
    #[IsGranted('ROLE_SCHOOL')]
    public function userDetail(string $userId): Response
    {
        $user = $this->em->getRepository(User::class)->find($userId);

        if ($user === null || $user->getDeletedAt() !== null) {
            throw $this->createNotFoundException('Utilisateur introuvable.');
        }

        $schoolProfiles = $this->em->createQueryBuilder()
            ->select('tp, p, s')
            ->from(SchoolProfile::class, 'tp')
            ->join('tp.profile', 'p')
            ->join('tp.school', 's')
            ->where('p.user = :user')
            ->andWhere('tp.deletedAt IS NULL')
            ->orderBy('s.name', 'ASC')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

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

        // Collect all SchoolProfile IDs linked to this user's profiles
        $schoolProfileIds = array_column(
            $this->em->createQuery(
                'SELECT tp.id FROM App\Entity\SchoolProfile tp
                 JOIN tp.profile p
                 WHERE p.user = :user'
            )
            ->setParameter('user', $user)
            ->getArrayResult(),
            'id'
        );

        if (!empty($schoolProfileIds)) {
            $this->em->createQuery('DELETE FROM App\Entity\SchoolProfileSeason tps WHERE tps.schoolProfileId IN (:ids)')
                ->setParameter('ids', $schoolProfileIds)->execute();
            $this->em->createQuery('DELETE FROM App\Entity\SchoolProfilePackage tpp WHERE tpp.schoolProfileId IN (:ids)')
                ->setParameter('ids', $schoolProfileIds)->execute();
            $this->em->createQuery('DELETE FROM App\Entity\EventOccurenceProfile eop WHERE eop.schoolProfileId IN (:ids)')
                ->setParameter('ids', $schoolProfileIds)->execute();
            $this->em->createQuery('DELETE FROM App\Entity\SchoolProfileGalaParticipation tpgp WHERE tpgp.schoolProfileId IN (:ids)')
                ->setParameter('ids', $schoolProfileIds)->execute();
            $this->em->createQuery('DELETE FROM App\Entity\SchoolProfile tp WHERE tp.id IN (:ids)')
                ->setParameter('ids', $schoolProfileIds)->execute();
        }

        // Detach profiles from the user then remove the user row
        $this->em->createQueryBuilder()
            ->update(Profile::class, 'p')
            ->set('p.user', ':null')
            ->where('p.user = :user')
            ->setParameter('null', null)
            ->setParameter('user', $user)
            ->getQuery()
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
