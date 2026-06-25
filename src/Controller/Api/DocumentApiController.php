<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Enum\SchoolRole;
use App\Repository\SchoolUserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

class DocumentApiController extends AbstractController
{
    private const MAX_FILE_SIZE_BYTES  = 50 * 1024 * 1024; // 50 MB
    private const ALLOWED_MIME_TYPES   = ['image/jpeg', 'image/png', 'image/webp'];
    private const ALLOWED_EXTENSIONS   = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * @param object|null $imagineCacheManager Liip\ImagineBundle\Imagine\Cache\CacheManager (optional — injected when LiipImagineBundle is registered)
     */
    public function __construct(
        private readonly SchoolUserRepository $schoolUserRepository,
        private readonly string $projectDir,
        private readonly ?object $imagineCacheManager = null,
    ) {
    }

    /**
     * POST /api/v1/schools/documents
     * Upload a document (image). UUID-renamed, MIME-checked, max 50 MB, JPEG/PNG/WebP only.
     * Saves to var/uploads/{schoolId}/{type}/ and invalidates LiipImagine cache.
     */
    #[Route('/api/v1/schools/documents', name: 'api_v1_teams_documents', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if ($user === null) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthenticated.'], 401);
        }

        $schoolId = $request->request->get('schoolId') ?? $request->query->get('schoolId');
        $type   = $request->request->get('type') ?? $request->query->get('type') ?? 'misc';

        if ($schoolId === null) {
            return new JsonResponse(['success' => false, 'error' => 'schoolId is required.'], 422);
        }

        // Verify school membership
        $schoolUser = $this->schoolUserRepository->findOneByUserAndSchool($user, (string) $schoolId);

        if ($schoolUser === null) {
            return new JsonResponse(['success' => false, 'error' => 'Forbidden.'], 403);
        }

        $file = $request->files->get('file');

        if ($file === null) {
            return new JsonResponse(['success' => false, 'error' => 'No file uploaded.'], 422);
        }

        // Size check
        if ($file->getSize() > self::MAX_FILE_SIZE_BYTES) {
            return new JsonResponse([
                'success' => false,
                'error'   => sprintf('File exceeds maximum size of %d MB.', self::MAX_FILE_SIZE_BYTES / 1024 / 1024),
            ], 422);
        }

        // MIME check via finfo
        $finfo    = new \finfo(\FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file->getRealPath());

        if (!\in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return new JsonResponse([
                'success' => false,
                'error'   => sprintf(
                    'Invalid file type "%s". Allowed types: %s.',
                    $mimeType,
                    implode(', ', self::ALLOWED_MIME_TYPES),
                ),
            ], 422);
        }

        // Extension from original filename for safety
        $originalExtension = strtolower($file->getClientOriginalExtension());

        if (!\in_array($originalExtension, self::ALLOWED_EXTENSIONS, true)) {
            $originalExtension = match ($mimeType) {
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                default      => 'bin',
            };
        }

        // Build destination path
        $uuid     = Uuid::v4()->toRfc4122();
        $filename = $uuid . '.' . $originalExtension;
        $destDir  = sprintf('%s/var/uploads/%s/%s', $this->projectDir, $schoolId, $type);

        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            return new JsonResponse(['success' => false, 'error' => 'Failed to create upload directory.'], 500);
        }

        try {
            $file->move($destDir, $filename);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => 'File move failed: ' . $e->getMessage()], 500);
        }

        $relativePath = sprintf('uploads/%s/%s/%s', $schoolId, $type, $filename);

        // Invalidate LiipImagine cache for this path (all filters) when the bundle is available
        if ($this->imagineCacheManager !== null && method_exists($this->imagineCacheManager, 'remove')) {
            try {
                $this->imagineCacheManager->remove($relativePath);
            } catch (\Throwable) {
                // Cache invalidation failure is non-critical
            }
        }

        return new JsonResponse([
            'success' => true,
            'path'    => $relativePath,
            'name'    => $filename,
        ], 201);
    }
}
