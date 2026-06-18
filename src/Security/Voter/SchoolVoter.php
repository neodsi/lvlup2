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
 *   schools:view             – any school member
 *   schools:update           – admin+
 *   schools:delete           – owner only
 *   schools:configure_stripe – admin+
 */
final class SchoolVoter extends Voter
{
    public const VIEW             = 'schools:view';
    public const UPDATE           = 'schools:update';
    public const DELETE           = 'schools:delete';
    public const CONFIGURE_STRIPE = 'schools:configure_stripe';

    private const SUPPORTED_ATTRIBUTES = [
        self::VIEW,
        self::UPDATE,
        self::DELETE,
        self::CONFIGURE_STRIPE,
    ];

    public function __construct(
        private readonly SchoolProfileRepository $schoolProfileRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, true)
            && $subject instanceof School;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var School $school */
        $school = $subject;

        $schoolRole = $this->resolveSchoolRole($user, $school->getId());

        if ($schoolRole === null) {
            return false;
        }

        return match ($attribute) {
            self::VIEW             => true,
            self::UPDATE           => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::Admin),
            self::DELETE           => $schoolRole === SchoolRole::Owner,
            self::CONFIGURE_STRIPE => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::Admin),
            default                => false,
        };
    }

    private function resolveSchoolRole(User $user, string $schoolId): ?SchoolRole
    {
        $schoolProfile = $this->schoolProfileRepository->findOneByUserAndSchool($user, $schoolId);

        return $schoolProfile?->getRole();
    }
}
