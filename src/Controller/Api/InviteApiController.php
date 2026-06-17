<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\GroupInvite;
use App\Entity\Team;
use App\Entity\TeamProfile;
use App\Entity\User;
use App\Enum\InviteStatus;
use App\Enum\TeamRole;
use App\Repository\TeamProfileRepository;
use App\Service\Email\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

class InviteApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TeamProfileRepository $teamProfileRepository,
        private readonly EmailService $emailService,
    ) {
    }

    /**
     * POST /api/v1/invites/accept
     * Accept a pending invitation. Requires authentication.
     */
    #[Route('/api/v1/invites/accept', name: 'api_v1_invites_accept', methods: ['POST'])]
    public function accept(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if ($user === null) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthenticated.'], 401);
        }

        $data  = json_decode($request->getContent(), true) ?? [];
        $token = $data['token'] ?? null;

        if ($token === null) {
            return new JsonResponse(['success' => false, 'error' => 'token is required.'], 422);
        }

        $invite = $this->em->getRepository(GroupInvite::class)->findOneBy(['token' => $token]);

        if ($invite === null) {
            return new JsonResponse(['success' => false, 'error' => 'Invitation not found.'], 404);
        }

        if ($invite->getStatus() !== InviteStatus::Pending) {
            return new JsonResponse([
                'success' => false,
                'error'   => sprintf('Invitation is already %s.', $invite->getStatus()->value),
            ], 409);
        }

        if ($invite->getExpiresAt() !== null && $invite->getExpiresAt() < new \DateTimeImmutable()) {
            $invite->setStatus(InviteStatus::Expired);
            $this->em->flush();

            return new JsonResponse(['success' => false, 'error' => 'Invitation has expired.'], 410);
        }

        $team = $this->em->getRepository(Team::class)->find($invite->getTeamId());

        if ($team === null) {
            return new JsonResponse(['success' => false, 'error' => 'Team not found.'], 404);
        }

        // Retrieve the primary Profile for this user
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

        $this->em->persist($teamProfile);

        $invite->setStatus(InviteStatus::Accepted);

        $this->em->flush();

        return new JsonResponse([
            'success'       => true,
            'teamProfileId' => $teamProfile->getId(),
        ]);
    }

    /**
     * POST /api/v1/invites/mails
     * Send invitation emails to a list of addresses. Requires team_admin.
     */
    #[Route('/api/v1/invites/mails', name: 'api_v1_invites_mails', methods: ['POST'])]
    public function mails(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if ($user === null) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthenticated.'], 401);
        }

        $data   = json_decode($request->getContent(), true) ?? [];
        $teamId = $data['teamId'] ?? null;
        $emails = $data['emails'] ?? [];
        $roleRaw = $data['role'] ?? TeamRole::TeamStudent->value;

        if ($teamId === null || empty($emails)) {
            return new JsonResponse(['success' => false, 'error' => 'teamId and emails[] are required.'], 422);
        }

        $teamProfile = $this->teamProfileRepository->findOneByUserAndTeam($user, (string) $teamId);

        if ($teamProfile === null) {
            return new JsonResponse(['success' => false, 'error' => 'Forbidden.'], 403);
        }

        $isAdmin = \in_array($teamProfile->getRole(), [TeamRole::TeamAdmin, TeamRole::TeamOwner], true);

        if (!$isAdmin) {
            return new JsonResponse(['success' => false, 'error' => 'team_admin role required.'], 403);
        }

        $team = $this->em->getRepository(Team::class)->find($teamId);

        if ($team === null) {
            return new JsonResponse(['success' => false, 'error' => 'Team not found.'], 404);
        }

        $role = $roleRaw instanceof TeamRole ? $roleRaw : TeamRole::from((string) $roleRaw);

        $sent   = [];
        $failed = [];

        foreach ($emails as $email) {
            $email = (string) $email;

            // Create a new invite token
            $invite = new GroupInvite();
            $invite->setTeamId($team->getId());
            $invite->setEmail($email);
            $invite->setRole($role);
            $invite->setToken(Uuid::v4()->toRfc4122());
            $invite->setInvitedBy($user->getId());
            $invite->setExpiresAt(new \DateTimeImmutable('+7 days'));

            $this->em->persist($invite);
            $this->em->flush();

            try {
                $this->emailService->sendInvitation($email, $team, $invite->getToken());
                $sent[] = $email;
            } catch (\Throwable) {
                $failed[] = $email;
            }
        }

        return new JsonResponse([
            'success' => true,
            'sent'    => $sent,
            'failed'  => $failed,
        ]);
    }
}
