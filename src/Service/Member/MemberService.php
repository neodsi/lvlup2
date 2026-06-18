<?php

declare(strict_types=1);

namespace App\Service\Member;

use App\Entity\Profile;
use App\Entity\Season;
use App\Entity\School;
use App\Entity\SchoolProfile;
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

    public function createMember(School $school, ?Season $season, array $data): SchoolProfile
    {
        $schoolProfile = null;

        $this->em->wrapInTransaction(function () use ($school, $season, $data, &$schoolProfile): void {
            // Create or reuse Profile
            $profile = null;

            if (isset($data['profileId'])) {
                $profile = $this->em->getRepository(Profile::class)->find($data['profileId']);
            }

            if ($profile === null) {
                $profile = new Profile();
                $profile->setFirstName($data['firstName']);
                $profile->setLastName($data['lastName']);

                if (isset($data['dob'])) {
                    $profile->setDob($data['dob']);
                }
                if (isset($data['phone'])) {
                    $profile->setPhone($data['phone']);
                }
                if (isset($data['gender'])) {
                    $profile->setGender($data['gender']);
                }
                if (isset($data['addressText'])) {
                    $profile->setAddressText($data['addressText']);
                }

                // Associate with user if provided
                if (isset($data['user']) && $data['user'] instanceof User) {
                    $profile->setUser($data['user']);
                }

                $this->em->persist($profile);
                $this->em->flush();
            }

            // Create SchoolProfile
            $schoolProfile = new SchoolProfile();
            $schoolProfile->setSchool($school);
            $schoolProfile->setProfile($profile);
            $schoolProfile->setStatus(SchoolProfileStatus::Accepted);

            $role = $data['role'] ?? SchoolRole::Student;
            $schoolProfile->setRole($role instanceof SchoolRole ? $role : SchoolRole::from($role));

            if (isset($data['status'])) {
                $schoolProfile->setStatus($data['status']);
            }
            if (isset($data['note'])) {
                $schoolProfile->setNote($data['note'] ?: null);
            }

            $this->em->persist($schoolProfile);
            $this->em->flush();

            if ($season === null) {
                return;
            }

            // Create SchoolProfileSeason for current season
            $tps = new SchoolProfileSeason();
            $tps->setSchoolProfileId($schoolProfile->getId());
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

        return $schoolProfile;
    }

    public function exportCsv(School $school, Season $season): string
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->em->createQuery(
            'SELECT tp, p, tps
             FROM App\Entity\SchoolProfile tp
             JOIN tp.profile p
             LEFT JOIN App\Entity\SchoolProfileSeason tps
                 WITH tps.schoolProfileId = tp.id AND tps.seasonId = :seasonId
             WHERE tp.school = :school
               AND tp.deletedAt IS NULL'
        )
            ->setParameter('school', $school)
            ->setParameter('seasonId', $season->getId())
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

        /** @var SchoolProfile $tp */
        foreach ($rows as $tp) {
            $profile = $tp->getProfile();

            // Fetch associated SchoolProfileSeason (may be null if member has no season entry)
            /** @var SchoolProfileSeason|null $tps */
            $tps = $this->em->getRepository(SchoolProfileSeason::class)->findOneBy([
                'schoolProfileId' => $tp->getId(),
                'seasonId'      => $season->getId(),
            ]);

            $email = null;
            if ($profile !== null && $profile->getUser() !== null) {
                $email = $profile->getUser()->getEmail();
            }

            $dob = $profile?->getDob()?->format('Y-m-d');

            $activityIds = $tps?->getActivityIds() !== null
                ? implode('|', $tps->getActivityIds())
                : '';

            $emergencyContact = $tps?->getEmergencyContact() !== null
                ? json_encode($tps->getEmergencyContact(), \JSON_UNESCAPED_UNICODE)
                : '';

            fputcsv($handle, [
                $tp->getId(),
                $profile?->getFirstName() ?? '',
                $profile?->getLastName() ?? '',
                $email ?? '',
                $profile?->getPhone() ?? '',
                $dob ?? '',
                $tp->getRole()->value,
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
