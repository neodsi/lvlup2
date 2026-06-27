<?php

declare(strict_types=1);

namespace App\Controller\School;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\PaymentSchedule;
use App\Entity\Season;
use App\Entity\User;
use App\Security\Voter\OrderVoter;
use App\Security\Voter\SchoolVoter;
use App\Service\SchoolContextService;
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
        private readonly SchoolContextService $schoolContext,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'school_orders_list', methods: ['GET'])]
    public function list(\Symfony\Component\HttpFoundation\Request $request): Response
    {
        /** @var User $user */
        $user        = $this->getUser();
        $school     = $this->schoolContext->getCurrentSchool();
        $schoolUser = $this->schoolContext->getCurrentSchoolUser($user);

        if ($school === null || $schoolUser === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

        if ($school->getCurrentSeasonId() === null) {
            return $this->redirectToRoute('school_settings_season_create');
        }

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
                return $this->redirectToRoute('school_orders_list', ['season' => $storedId]);
            }
            $season = null;
        }

        $orders = $this->em->getRepository(Order::class)->findBy([
            'schoolProfileId' => $schoolUser->getId(),
            'schoolId'        => $school->getId(),
        ], ['createdAt' => 'DESC']);

        return $this->render('school/orders/list.html.twig', [
            'school'   => $school,
            'orders' => $orders,
            'season' => $season,
        ]);
    }

    #[Route('/{id}', name: 'school_orders_detail', methods: ['GET'])]
    public function detail(string $id): Response
    {
        /** @var User $user */
        $user  = $this->getUser();
        $school  = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolUser($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $order = $this->em->getRepository(Order::class)->find($id);

        if ($order === null || $order->getSchoolId() !== $school->getId()) {
            throw $this->createNotFoundException('Order not found.');
        }

        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $order);

        $items = $this->em->getRepository(OrderItem::class)->findBy(['orderId' => $order->getId()]);
        $schedules = $this->em->getRepository(PaymentSchedule::class)->findBy(['orderId' => $order->getId()]);

        return $this->render('school/orders/detail.html.twig', [
            'school'      => $school,
            'order'     => $order,
            'items'     => $items,
            'schedules' => $schedules,
        ]);
    }
}
