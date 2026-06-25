<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Season;
use App\Entity\User;
use App\Enum\SchoolRole;
use App\Repository\SchoolUserRepository;
use App\Security\SchoolRoleHierarchy;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Permissions:
 *   seasons:view   – any school member
 *   seasons:create – admin+
 *   seasons:update – admin+
 *   seasons:delete – admin+
 */
final class SeasonVoter extends Voter
{
    public const VIEW   = 'seasons:view';
    public const CREATE = 'seasons:create';
    public const UPDATE = 'seasons:update';
    public const DELETE = 'seasons:delete';

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
            && $subject instanceof Season;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Season $season */
        $season = $subject;

        $schoolRole = $this->resolveSchoolRole($user, $season->getSchoolId());

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
