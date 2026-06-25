<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Event;
use App\Entity\User;
use App\Enum\SchoolRole;
use App\Repository\SchoolUserRepository;
use App\Security\SchoolRoleHierarchy;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Permissions:
 *   events:view   – any school member
 *   events:create – admin+
 *   events:update – admin+
 *   events:delete – admin+
 */
final class EventVoter extends Voter
{
    public const VIEW   = 'events:view';
    public const CREATE = 'events:create';
    public const UPDATE = 'events:update';
    public const DELETE = 'events:delete';

    private const SUPPORTED_ATTRIBUTES = [
        self::VIEW,
        self::CREATE,
        self::UPDATE,
        self::DELETE,
    ];

    public function __construct(
        private readonly SchoolUserRepository $schoolUserRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, true)
            && $subject instanceof Event;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Event $event */
        $event = $subject;

        $schoolRole = $this->resolveSchoolRole($user, $event->getSchoolId());

        if ($schoolRole === null) {
            return false;
        }

        return match ($attribute) {
            self::VIEW   => true,
            self::CREATE => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
            self::UPDATE => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
            self::DELETE => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
            default      => false,
        };
    }

    private function resolveSchoolRole(User $user, string $schoolId): ?SchoolRole
    {
        $schoolUser = $this->schoolUserRepository->findOneByUserAndSchool($user, $schoolId);

        return $schoolUser?->getRole();
    }
}
