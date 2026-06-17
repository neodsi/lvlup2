<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Team;
use App\Entity\User;
use App\Enum\TeamStatus;
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
        $totalTeams = $this->em->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Team::class, 't')
            ->getQuery()
            ->getSingleScalarResult();

        $totalUsers = $this->em->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where('u.deletedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        $teamsByStatus = $this->em->createQueryBuilder()
            ->select('t.status, COUNT(t.id) AS cnt')
            ->from(Team::class, 't')
            ->groupBy('t.status')
            ->getQuery()
            ->getResult();

        return $this->render('admin/dashboard.html.twig', [
            'totalTeams'     => (int) $totalTeams,
            'totalUsers'     => (int) $totalUsers,
            'teamsByStatus'  => $teamsByStatus,
        ]);
    }

    #[Route('/admin/schools', name: 'app_admin_schools')]
    #[IsGranted('ROLE_SCHOOL')]
    public function schools(Request $request): Response
    {
        $statusFilter = $request->query->get('status');

        $qb = $this->em->createQueryBuilder()
            ->select('t')
            ->from(Team::class, 't')
            ->orderBy('t.createdAt', 'DESC');

        if ($statusFilter !== null && $statusFilter !== '') {
            $status = TeamStatus::tryFrom($statusFilter);
            if ($status !== null) {
                $qb->where('t.status = :status')
                   ->setParameter('status', $status);
            }
        }

        $teams = $qb->getQuery()->getResult();

        return $this->render('admin/schools/index.html.twig', [
            'teams'        => $teams,
            'statusFilter' => $statusFilter,
            'statuses'     => TeamStatus::cases(),
        ]);
    }

    #[Route('/admin/schools/{id}', name: 'app_admin_school_detail')]
    #[IsGranted('ROLE_SCHOOL')]
    public function schoolDetail(string $id, Request $request): Response
    {
        $team = $this->em->getRepository(Team::class)->find($id);

        if ($team === null) {
            throw $this->createNotFoundException('School not found.');
        }

        if ($request->isMethod('POST')) {
            $newStatus = $request->request->get('status');
            $status = TeamStatus::tryFrom((string) $newStatus);

            if ($status !== null) {
                $team->setStatus($status);
                $this->em->flush();

                $this->addFlash('success', sprintf('School status updated to "%s".', $status->value));
            } else {
                $this->addFlash('error', 'Invalid status value.');
            }

            return $this->redirectToRoute('app_admin_school_detail', ['id' => $id]);
        }

        return $this->render('admin/schools/detail.html.twig', [
            'team'     => $team,
            'statuses' => TeamStatus::cases(),
        ]);
    }

    #[Route('/admin/users', name: 'app_admin_users')]
    #[IsGranted('ROLE_SCHOOL')]
    public function users(Request $request): Response
    {
        $search = $request->query->get('q', '');

        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.deletedAt IS NULL')
            ->orderBy('u.createdAt', 'DESC');

        if ($search !== '') {
            $qb->andWhere('u.email LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        $users = $qb->getQuery()->getResult();

        return $this->render('admin/users/index.html.twig', [
            'users'  => $users,
            'search' => $search,
        ]);
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
        return $this->redirect('/?_switch_user=_exit');
    }
}
