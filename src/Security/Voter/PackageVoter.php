<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Package;
use App\Entity\User;
use App\Enum\SchoolRole;
use App\Repository\SchoolProfileRepository;
use App\Security\SchoolRoleHierarchy;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Permissions:
 *   packages:view   – any school member
 *   packages:create – admin+
 *   packages:update – admin+
 *   packages:delete – admin+
 */
final class PackageVoter extends Voter
{
    public const VIEW   = 'packages:view';
    public const CREATE = 'packages:create';
    public const UPDATE = 'packages:update';
    public const DELETE = 'packages:delete';

    private const SUPPORTED_ATTRIBUTES = [
        self::VIEW,
        self::CREATE,
        self::UPDATE,
        self::DELETE,
    ];

    public function __construct(
        private readonly SchoolProfileRepository $schoolProfileRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, true)
            && $subject instanceof Package;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Package $package */
        $package = $subject;

        $schoolRole = $this->resolveSchoolRole($user, $package->getSchoolId());

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
        $schoolProfile = $this->schoolProfileRepository->findOneByUserAndSchool($user, $schoolId);

        return $schoolProfile?->getRole();
    }
}
