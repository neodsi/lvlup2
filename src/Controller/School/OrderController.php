<?php

declare(strict_types=1);

namespace App\Controller\School;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\PaymentSchedule;
use App\Entity\User;
use App\Security\Voter\OrderVoter;
use App\Security\Voter\TeamVoter;
use App\Service\TeamContextService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/school/orders')]
#[IsGranted('ROLE_USER')]
final class OrderController extends AbstractController
{
    public function __construct(
        private readonly TeamContextService $teamContext,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'school_orders_list', methods: ['GET'])]
    public function list(): Response
    {
        /** @var User $user */
        $user        = $this->getUser();
        $team        = $this->teamContext->getCurrentTeam();
        $teamProfile = $this->teamContext->getCurrentTeamProfile($user);

        if ($team === null || $teamProfile === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::VIEW, $team);

        $orders = $this->em->getRepository(Order::class)->findBy([
            'teamProfileId' => $teamProfile->getId(),
            'teamId'        => $team->getId(),
        ], ['createdAt' => 'DESC']);

        return $this->render('school/orders/list.html.twig', [
            'team'   => $team,
            'orders' => $orders,
        ]);
    }

    #[Route('/{id}', name: 'school_orders_detail', methods: ['GET'])]
    public function detail(string $id): Response
    {
        /** @var User $user */
        $user  = $this->getUser();
        $team  = $this->teamContext->getCurrentTeam();

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $order = $this->em->getRepository(Order::class)->find($id);

        if ($order === null || $order->getTeamId() !== $team->getId()) {
            throw $this->createNotFoundException('Order not found.');
        }

        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $order);

        $items = $this->em->getRepository(OrderItem::class)->findBy(['orderId' => $order->getId()]);
        $schedules = $this->em->getRepository(PaymentSchedule::class)->findBy(['orderId' => $order->getId()]);

        return $this->render('school/orders/detail.html.twig', [
            'team'      => $team,
            'order'     => $order,
            'items'     => $items,
            'schedules' => $schedules,
        ]);
    }
}
