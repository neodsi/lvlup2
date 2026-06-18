<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Payment;
use App\Entity\User;
use App\Enum\SchoolRole;
use App\Repository\SchoolProfileRepository;
use App\Security\SchoolRoleHierarchy;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Permissions:
 *   payments:view   – any school member
 *   payments:create – any school member
 *   payments:refund – admin+ AND the payment belongs to their school
 */
final class PaymentVoter extends Voter
{
    public const VIEW   = 'payments:view';
    public const CREATE = 'payments:create';
    public const REFUND = 'payments:refund';

    private const SUPPORTED_ATTRIBUTES = [
        self::VIEW,
        self::CREATE,
        self::REFUND,
    ];

    public function __construct(
        private readonly SchoolProfileRepository $schoolProfileRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, true)
            && $subject instanceof Payment;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Payment $payment */
        $payment = $subject;

        $schoolRole = $this->resolveSchoolRole($user, $payment->getSchoolId());

        if ($schoolRole === null) {
            return false;
        }

        return match ($attribute) {
            self::VIEW   => true,
            self::CREATE => true,
            self::REFUND => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
            default      => false,
        };
    }

    private function resolveSchoolRole(User $user, string $schoolId): ?SchoolRole
    {
        $schoolProfile = $this->schoolProfileRepository->findOneByUserAndSchool($user, $schoolId);

        return $schoolProfile?->getRole();
    }
}
