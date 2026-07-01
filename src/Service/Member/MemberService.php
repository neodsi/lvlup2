<?php

declare(strict_types=1);

namespace App\Service\Member;

use App\Entity\Profile;
use App\Entity\Season;
use App\Entity\School;
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

    public function createMember(School $school, Season $season, array $data): SchoolProfileSeason
    {
        $sps = null;

        $this->em->wrapInTransaction(function () use ($school, $season, $data, &$sps): void {
            /** @var User $user */
            $user = $data['user'];

            // Find or create a minimal Profile for this user
            $profile = $user->getProfile();

            if (!$profile instanceof Profile) {
                $profile = new Profile();
                $profile->setUser($user);
                $profile->setFirstName('');
                $profile->setLastName('');
                $this->em->persist($profile);
                $this->em->flush();
            }

            $role = $data['role'] ?? SchoolRole::Student;
            $role = $role instanceof SchoolRole ? $role : SchoolRole::from($role);

            $isStudent = $role === SchoolRole::Student;
            if (array_key_exists('status', $data)) {
                $statusRaw = $data['status'];
                $status = $statusRaw instanceof SchoolProfileStatus ? $statusRaw : ($statusRaw ? SchoolProfileStatus::from($statusRaw) : null);
            } else {
                $status = $isStudent ? null : SchoolProfileStatus::Accepted;
            }

            $sps = new SchoolProfileSeason();
            $sps->setProfileId($profile->getId());
            $sps->setSeasonId($season->getId());
            $sps->setSchoolId($school->getId());
            $sps->setRole($role);
            $sps->setRegistrationStatus($status);

            if (isset($data['note'])) {
                $sps->setNote($data['note'] ?: null);
            }
            if (isset($data['consentAccepted'])) {
                $sps->setConsentAccepted($data['consentAccepted'] ?: null);
            }

            $this->em->persist($sps);
        });

        return $sps;
    }

    public function exportCsv(School $school, Season $season): string
    {
        /** @var SchoolProfileSeason[] $tpsList */
        $tpsList = $this->em->createQuery(
            'SELECT tps FROM App\Entity\SchoolProfileSeason tps
             WHERE tps.schoolId = :schoolId AND tps.seasonId = :seasonId'
        )
            ->setParameter('schoolId', $school->getId())
            ->setParameter('seasonId', $season->getId())
            ->getResult();

        $profileIds = array_map(fn(SchoolProfileSeason $tps) => $tps->getProfileId(), $tpsList);
        $profilesById = [];
        if (!empty($profileIds)) {
            $profileList = $this->em->createQuery(
                'SELECT p FROM App\Entity\Profile p WHERE p.id IN (:ids)'
            )->setParameter('ids', array_unique($profileIds))->getResult();
            foreach ($profileList as $p) {
                $profilesById[$p->getId()] = $p;
            }
        }

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not open temp stream for CSV export.');
        }

        fputcsv($handle, ['id', 'first_name', 'last_name', 'email', 'phone', 'dob', 'role', 'status', 'note']);

        foreach ($tpsList as $tps) {
            $profile = $profilesById[$tps->getProfileId()] ?? null;
            $email   = $profile?->getUser()?->getEmail();
            $dob     = $profile?->getDob()?->format('Y-m-d');

            fputcsv($handle, [
                $tps->getId(),
                $profile?->getFirstName() ?? '',
                $profile?->getLastName() ?? '',
                $email ?? '',
                $profile?->getPhone() ?? '',
                $dob ?? '',
                $tps->getRole()->value,
                $tps->getRegistrationStatus()?->value ?? '',
                $tps->getNote() ?? '',
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv !== false ? $csv : '';
    }

    /**
     * Recalculates users.roles from all SchoolProfileSeasons.
     * Preserves ROLE_ADMIN (set manually). Call after any SchoolProfileSeason create/delete.
     */
    public function syncUserRoles(User $user): void
    {
        $roleMap = [
            SchoolRole::School->value  => 'ROLE_SCHOOL',
            SchoolRole::Teacher->value => 'ROLE_TEACHER',
            SchoolRole::Student->value => 'ROLE_STUDENT',
        ];

        $profile = $user->getProfile();
        if ($profile === null) {
            return;
        }

        $rows = $this->em->createQueryBuilder()
            ->select('DISTINCT tps.role')
            ->from(SchoolProfileSeason::class, 'tps')
            ->where('tps.profileId = :profileId')
            ->setParameter('profileId', $profile->getId())
            ->getQuery()
            ->getSingleColumnResult();

        $derived = [];
        foreach ($rows as $roleValue) {
            $mapped = $roleMap[$roleValue instanceof SchoolRole ? $roleValue->value : (string) $roleValue] ?? null;
            if ($mapped !== null) {
                $derived[] = $mapped;
            }
        }

        // Preserve roles not managed by SPS derivation
        $current = array_filter($user->getRoles(), static fn(string $r) => $r !== 'ROLE_USER');
        $managed = ['ROLE_SCHOOL', 'ROLE_TEACHER', 'ROLE_STUDENT'];
        foreach ($current as $r) {
            if (!in_array($r, $managed, true)) {
                $derived[] = $r;
            }
        }

        // Also grant ROLE_SCHOOL if this user owns any school (ownerProfileId, without needing an SPS)
        if ($profile !== null) {
            $ownsSchool = (int) $this->em->createQuery(
                'SELECT COUNT(s.id) FROM App\Entity\School s WHERE s.ownerProfileId = :profileId'
            )->setParameter('profileId', $profile->getId())->getSingleScalarResult() > 0;

            if ($ownsSchool) {
                $derived[] = 'ROLE_SCHOOL';
            }
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

        $this->recalculatePackageStatus($package);

        $this->em->persist($package);
        $this->em->flush();

        return $package;
    }

    private function recalculatePackageStatus(SchoolProfilePackage $package): void
    {
        $now = new \DateTimeImmutable();

        if ($package->getExpiresAt() !== null && $package->getExpiresAt() < $now) {
            $package->setStatus(PackageStatus::Expired);

            return;
        }

        if (
            $package->getType() === 'a_la_carte'
            && $package->getClassesQty() !== null
            && $package->getClassesDone() >= $package->getClassesQty()
        ) {
            $package->setStatus(PackageStatus::Exhausted);

            return;
        }

        if ($package->getStatus() === PackageStatus::Exhausted || $package->getStatus() === PackageStatus::Expired) {
            $package->setStatus(PackageStatus::Active);
        }
    }
}
