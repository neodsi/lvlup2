<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\GroupInvite;
use App\Entity\Team;
use App\Entity\TeamProfile;
use App\Enum\InviteStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class InvitationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/invitations/{token}', name: 'app_invitation', methods: ['GET', 'POST'])]
    public function invitation(string $token, Request $request): Response
    {
        /** @var GroupInvite|null $invite */
        $invite = $this->entityManager
            ->getRepository(GroupInvite::class)
            ->findOneBy(['token' => $token]);

        if ($invite === null || $invite->getStatus() !== InviteStatus::Pending) {
            throw $this->createNotFoundException('Cette invitation est introuvable ou a déjà été utilisée.');
        }

        if ($invite->getExpiresAt() !== null && $invite->getExpiresAt() < new \DateTimeImmutable()) {
            $invite->setStatus(InviteStatus::Expired);
            $this->entityManager->flush();

            throw $this->createNotFoundException('Cette invitation a expiré.');
        }

        /** @var Team|null $team */
        $team = $this->entityManager
            ->getRepository(Team::class)
            ->find($invite->getTeamId());

        if ($request->isMethod('GET')) {
            return $this->render('auth/invitation.html.twig', [
                'invite' => $invite,
                'team'   => $team,
            ]);
        }

        // POST: accept the invitation — authentication required.
        $user = $this->getUser();
        if ($user === null) {
            return $this->redirect('/login?_target_path=' . urlencode('/invitations/' . $token));
        }

        $submittedToken = (string) $request->request->get('_csrf_token');
        if (!$this->isCsrfTokenValid('invitation_accept_' . $token, $submittedToken)) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->render('auth/invitation.html.twig', [
                'invite' => $invite,
                'team'   => $team,
            ]);
        }

        // Retrieve the primary Profile linked to the authenticated user.
        $profile = null;
        if (method_exists($user, 'getProfiles')) {
            foreach ($user->getProfiles() as $p) {
                if ($p->isPrimary()) {
                    $profile = $p;
                    break;
                }
            }
        }

        $teamProfile = new TeamProfile();
        $teamProfile->setTeam($team);
        $teamProfile->setProfile($profile);
        $teamProfile->setRole($invite->getRole());

        $this->entityManager->persist($teamProfile);

        $invite->setStatus(InviteStatus::Accepted);

        $this->entityManager->flush();

        $this->addFlash('success', 'Vous avez rejoint l\'équipe avec succès.');

        return $this->redirect('/home');
    }
}
