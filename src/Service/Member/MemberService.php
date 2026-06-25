<?php

declare(strict_types=1);

namespace App\Service\Member;

use App\Entity\Profile;
use App\Entity\Season;
use App\Entity\School;
use App\Entity\SchoolUser;
use App\Entity\SchoolProfilePackage;
use App\Entity\SchoolProfileSeason;
use App\Entity\User;
use App\Enum\PackageStatus;
use App\Enum\SchoolProfileStatus;
use App\Enum\SchoolRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;


class MemberService
{
    private const CANCEL_WINDOW_SESSION_KEY = 'fastcount_last_remove';
    private const CANCEL_WINDOW_SECONDS     = 300; // 5 minutes

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function createMember(School $school, ?Season $season, array $data): SchoolUser
    {
        $schoolUser = null;

        $this->em->wrapInTransaction(function () use ($school, $season, $data, &$schoolUser): void {
            /** @var User $user */
            $user = $data['user'];

            // Find or create a minimal Profile for this user
            $profile = $user->getProfiles()
                ->filter(fn(Profile $p) => $p->getDeletedAt() === null)
                ->first();

            if (!$profile instanceof Profile) {
                $profile = new Profile();
                $profile->setUser($user);
                $profile->setFirstName('');
                $profile->setLastName('');
                $this->em->persist($profile);
                $this->em->flush();
            }

            // Create SchoolUser
            $schoolUser = new SchoolUser();
            $schoolUser->setSchool($school);
            $schoolUser->setUser($user);
            $schoolUser->setStatus(SchoolProfileStatus::Accepted);

            $role = $data['role'] ?? SchoolRole::Student;
            $schoolUser->setRole($role instanceof SchoolRole ? $role : SchoolRole::from($role));

            if (isset($data['status'])) {
                $schoolUser->setStatus($data['status']);
            }
            if (isset($data['note'])) {
                $schoolUser->setNote($data['note'] ?: null);
            }

            $this->em->persist($schoolUser);
            $this->em->flush();

            if ($season === null) {
                return;
            }

            // Create SchoolProfileSeason for current season
            $tps = new SchoolProfileSeason();
            $tps->setSchoolProfileId($schoolUser->getId());
            $tps->setSeasonId($season->getId());
            $tps->setSchoolId($school->getId());

            if (isset($data['registrationStatus'])) {
                $tps->setRegistrationStatus($data['registrationStatus']);
            }
            if (isset($data['activityIds'])) {
                $tps->setActivityIds($data['activityIds']);
            }
            if (isset($data['ageGroupId'])) {
                $tps->setAgeGroupId($data['ageGroupId']);
            }
            if (isset($data['levelId'])) {
                $tps->setLevelId($data['levelId']);
            }
            if (isset($data['emergencyContact'])) {
                $tps->setEmergencyContact($data['emergencyContact']);
            }
            if (isset($data['injuryWarning'])) {
                $tps->setInjuryWarning($data['injuryWarning']);
            }
            if (isset($data['accepted'])) {
                $tps->setAccepted($data['accepted'] ?: null);
            }

            $this->em->persist($tps);
        });

        return $schoolUser;
    }

    public function exportCsv(School $school, Season $season): string
    {
        /** @var array<SchoolUser> $members */
        $members = $this->em->createQuery(
            'SELECT su, u FROM App\Entity\SchoolUser su
             JOIN su.user u
             WHERE su.school = :school AND su.deletedAt IS NULL'
        )
            ->setParameter('school', $school)
            ->getResult();

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not open temp stream for CSV export.');
        }

        // Header row
        fputcsv($handle, [
            'id',
            'first_name',
            'last_name',
            'email',
            'phone',
            'dob',
            'role',
            'registration_status',
            'activity_ids',
            'age_group',
            'level',
            'emergency_contact',
            'injury_warning',
        ]);

        /** @var SchoolUser $su */
        foreach ($members as $su) {
            $profile = $su->getProfile();

            // Fetch associated SchoolProfileSeason (may be null if member has no season entry)
            /** @var SchoolProfileSeason|null $tps */
            $tps = $this->em->getRepository(SchoolProfileSeason::class)->findOneBy([
                'schoolProfileId' => $su->getId(),
                'seasonId'        => $season->getId(),
            ]);

            $email = $su->getUser()->getEmail();

            $dob = $profile?->getDob()?->format('Y-m-d');

            $activityIds = $tps?->getActivityIds() !== null
                ? implode('|', $tps->getActivityIds())
                : '';

            $emergencyContact = $tps?->getEmergencyContact() !== null
                ? json_encode($tps->getEmergencyContact(), \JSON_UNESCAPED_UNICODE)
                : '';

            fputcsv($handle, [
                $su->getId(),
                $profile?->getFirstName() ?? '',
                $profile?->getLastName() ?? '',
                $email ?? '',
                $profile?->getPhone() ?? '',
                $dob ?? '',
                $su->getRole()->value,
                $tps?->getRegistrationStatus()->value ?? '',
                $activityIds,
                $tps?->getAgeGroupId() ?? '',
                $tps?->getLevelId() ?? '',
                $emergencyContact,
                $tps?->getInjuryWarning() ?? '',
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv !== false ? $csv : '';
    }

    /**
     * Recalculates users.roles from all active school_users.
     * Preserves ROLE_ADMIN (set manually). Call after any SchoolUser create/delete.
     */
    public function syncUserRoles(User $user): void
    {
        // Mapping school_users.role → users.roles
        $roleMap = [
            SchoolRole::School->value  => 'ROLE_SCHOOL',
            SchoolRole::Teacher->value => 'ROLE_TEACHER',
            SchoolRole::Student->value => 'ROLE_STUDENT',
        ];

        $rows = $this->em->createQueryBuilder()
            ->select('DISTINCT su.role')
            ->from(SchoolUser::class, 'su')
            ->where('su.user = :user')
            ->andWhere('su.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleColumnResult();

        $derived = [];
        foreach ($rows as $roleValue) {
            $mapped = $roleMap[$roleValue instanceof SchoolRole ? $roleValue->value : (string) $roleValue] ?? null;
            if ($mapped !== null) {
                $derived[] = $mapped;
            }
        }

        // Always preserve ROLE_ADMIN if already set
        $current = array_filter($user->getRoles(), static fn(string $r) => $r !== 'ROLE_USER');
        if (in_array('ROLE_ADMIN', $current, true)) {
            $derived[] = 'ROLE_ADMIN';
        }

        $user->setRoles(array_values(array_unique($derived)));
    }

    public function fastCount(SchoolProfilePackage $package, User $actor, string $action): SchoolProfilePackage
    {
        $session    = $this->requestStack->getSession();
        $sessionKey = self::CANCEL_WINDOW_SESSION_KEY . '_' . $package->getId();

        if ($action === 'remove-one') {
            $package->setClassesDone($package->getClassesDone() + 1);
            $session->set($sessionKey, time());
        } elseif ($action === 'cancel-remove') {
            $lastRemove = $session->get($sessionKey);

            if ($lastRemove !== null && (time() - (int) $lastRemove) <= self::CANCEL_WINDOW_SECONDS) {
                $newCount = max(0, $package->getClassesDone() - 1);
                $package->setClassesDone($newCount);
                $session->remove($sessionKey);
            }
        }

        // Recalculate status
        $this->recalculatePackageStatus($package);

        $this->em->persist($package);
        $this->em->flush();

        return $package;
    }

    private function recalculatePackageStatus(SchoolProfilePackage $package): void
    {
        $now = new \DateTimeImmutable();

        // Check expiry first
        if ($package->getExpiresAt() !== null && $package->getExpiresAt() < $now) {
            $package->setStatus(PackageStatus::Expired);

            return;
        }

        // Check exhaustion for a_la_carte type
        if (
            $package->getType() === 'a_la_carte'
            && $package->getClassesQty() !== null
            && $package->getClassesDone() >= $package->getClassesQty()
        ) {
            $package->setStatus(PackageStatus::Exhausted);

            return;
        }

        // Default: keep active if it was active or set to active
        if ($package->getStatus() === PackageStatus::Exhausted || $package->getStatus() === PackageStatus::Expired) {
            $package->setStatus(PackageStatus::Active);
        }
    }
}
