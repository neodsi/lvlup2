<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\School;
use App\Entity\SchoolProfile;
use App\Entity\User;
use App\Enum\SchoolRole;
use App\Repository\SchoolProfileRepository;
use App\Security\SchoolRoleHierarchy;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Permissions:
 *   members:view             – teacher+
 *   members:create           – admin+
 *   members:update           – admin+
 *   members:delete           – admin+
 *   members:export_csv       – admin+
 *   school_profiles:export_csv – admin+
 *
 * Subject: School (school-scoped actions) or SchoolProfile (member-scoped actions).
 */
final class MemberVoter extends Voter
{
    public const VIEW               = 'members:view';
    public const CREATE             = 'members:create';
    public const UPDATE             = 'members:update';
    public const DELETE             = 'members:delete';
    public const EXPORT_CSV         = 'members:export_csv';
    public const SCHOOL_PROFILES_EXPORT_CSV = 'school_profiles:export_csv';

    private const SUPPORTED_ATTRIBUTES = [
        self::VIEW,
        self::CREATE,
        self::UPDATE,
        self::DELETE,
        self::EXPORT_CSV,
        self::SCHOOL_PROFILES_EXPORT_CSV,
    ];

    public function __construct(
        private readonly SchoolProfileRepository $schoolProfileRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, self::SUPPORTED_ATTRIBUTES, true)) {
            return false;
        }

        return $subject instanceof School || $subject instanceof SchoolProfile;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        $schoolId = match (true) {
            $subject instanceof School        => $subject->getId(),
            $subject instanceof SchoolProfile => $subject->getSchool()?->getId(),
            default                         => null,
        };

        if ($schoolId === null) {
            return false;
        }

        $schoolRole = $this->resolveSchoolRole($user, $schoolId);

        if ($schoolRole === null) {
            return false;
        }

        return match ($attribute) {
            self::VIEW                    => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::Teacher),
            self::CREATE                  => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
            self::UPDATE                  => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
            self::DELETE                  => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
            self::EXPORT_CSV              => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
            self::SCHOOL_PROFILES_EXPORT_CSV => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
            default                       => false,
        };
    }

    private function resolveSchoolRole(User $user, string $schoolId): ?SchoolRole
    {
        $schoolProfile = $this->schoolProfileRepository->findOneByUserAndSchool($user, $schoolId);

        return $schoolProfile?->getRole();
    }
}
