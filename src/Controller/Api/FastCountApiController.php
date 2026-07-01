<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\School;
use App\Entity\SchoolProfilePackage;
use App\Entity\SchoolProfileSeason;
use App\Entity\User;
use App\Enum\SchoolRole;
use App\Service\Member\MemberService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class FastCountApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MemberService $memberService,
    ) {
    }

    /**
     * POST /api/v1/schools/{schoolId}/school-profile-packages/{id}/fast-count/remove-one
     * Decrement one class from the package count. Requires teacher or higher.
     */
    #[Route(
        '/api/v1/schools/{schoolId}/school-profile-packages/{id}/fast-count/remove-one',
        name: 'api_v1_fast_count_remove_one',
        methods: ['POST'],
    )]
    public function removeOne(string $schoolId, string $id): JsonResponse
    {
        $authResponse = $this->requireSchoolTeacher($schoolId);
        if ($authResponse !== null) {
            return $authResponse;
        }

        return $this->handleFastCount($schoolId, $id, 'remove-one');
    }

    /**
     * POST /api/v1/schools/{schoolId}/school-profile-packages/{id}/fast-count/cancel-remove
     * Cancel the last remove-one within the allowed window. Requires teacher or higher.
     */
    #[Route(
        '/api/v1/schools/{schoolId}/school-profile-packages/{id}/fast-count/cancel-remove',
        name: 'api_v1_fast_count_cancel_remove',
        methods: ['POST'],
    )]
    public function cancelRemove(string $schoolId, string $id): JsonResponse
    {
        $authResponse = $this->requireSchoolTeacher($schoolId);
        if ($authResponse !== null) {
            return $authResponse;
        }

        return $this->handleFastCount($schoolId, $id, 'cancel-remove');
    }

    private function handleFastCount(string $schoolId, string $id, string $action): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $package = $this->em->getRepository(SchoolProfilePackage::class)->find($id);

        if ($package === null || $package->getSchoolId() !== $schoolId) {
            return new JsonResponse(['success' => false, 'error' => 'SchoolProfilePackage not found.'], 404);
        }

        try {
            $updated = $this->memberService->fastCount($package, $user, $action);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return new JsonResponse([
            'success'     => true,
            'classesDone' => $updated->getClassesDone(),
            'classesQty'  => $updated->getClassesQty(),
            'status'      => $updated->getStatus()->value,
        ]);
    }

    private function requireSchoolTeacher(string $schoolId): ?JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if ($user === null) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthenticated.'], 401);
        }

        $role = $this->resolveSchoolRole($user, $schoolId);

        if ($role === null) {
            return new JsonResponse(['success' => false, 'error' => 'Forbidden.'], 403);
        }

        if (!\in_array($role, [SchoolRole::Teacher, SchoolRole::School], true)) {
            return new JsonResponse(['success' => false, 'error' => 'teacher role or higher required.'], 403);
        }

        return null;
    }

    private function resolveSchoolRole(User $user, string $schoolId): ?SchoolRole
    {
        $profile = $user->getProfile();
        if ($profile === null) {
            return null;
        }

        $school = $this->em->getRepository(School::class)->find($schoolId);
        if ($school !== null && $school->getOwnerProfileId() !== null && $school->getOwnerProfileId() === $profile->getId()) {
            return SchoolRole::School;
        }

        $sps = $this->em->createQueryBuilder()
            ->select('sps')
            ->from(SchoolProfileSeason::class, 'sps')
            ->where('sps.profileId = :profileId')
            ->andWhere('sps.schoolId = :schoolId')
            ->orderBy('sps.createdAt', 'DESC')
            ->setMaxResults(1)
            ->setParameter('profileId', $profile->getId())
            ->setParameter('schoolId', $schoolId)
            ->getQuery()
            ->getOneOrNullResult();

        return $sps?->getRole();
    }
}
