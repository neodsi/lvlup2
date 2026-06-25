<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\School;
use App\Repository\SchoolRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SchoolDirectoryController extends AbstractController
{
    private const PER_PAGE = 12;

    public function __construct(
        private readonly SchoolRepository $schoolRepository,
    ) {
    }

    #[Route('/ecoles-de-danse', name: 'app_school_directory', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $q    = trim((string) $request->query->get('q', ''));
        $page = max(1, (int) $request->query->get('page', 1));

        ['results' => $schools, 'total' => $total, 'mapData' => $mapData] =
            $this->schoolRepository->findForDirectory($q, $page, self::PER_PAGE);

        return $this->render('public/school_directory.html.twig', [
            'schools'    => $schools,
            'mapJson'    => $this->buildMapJson($mapData),
            'q'          => $q,
            'total'      => $total,
            'pagination' => [
                'currentPage'  => $page,
                'totalPages'   => max(1, (int) ceil($total / self::PER_PAGE)),
                'totalItems'   => $total,
                'itemsPerPage' => self::PER_PAGE,
                'routeName'    => 'app_school_directory',
                'routeParams'  => $q !== '' ? ['q' => $q] : [],
            ],
        ]);
    }

    #[Route('/ecoles-de-danse/{citySlug}', name: 'app_school_directory_city', methods: ['GET'],
        requirements: ['citySlug' => '[a-z0-9-]+'])]
    public function city(string $citySlug, Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));

        ['results' => $schools, 'total' => $total, 'mapData' => $mapData] =
            $this->schoolRepository->findByCitySlug($citySlug, $page, self::PER_PAGE);

        if ($total === 0 && $page === 1) {
            throw $this->createNotFoundException('Aucune école de danse trouvée pour cette ville.');
        }

        $cityName = mb_convert_case(str_replace('-', ' ', $citySlug), MB_CASE_TITLE, 'UTF-8');

        return $this->render('public/school_city.html.twig', [
            'schools'    => $schools,
            'mapJson'    => $this->buildMapJson($mapData),
            'citySlug'   => $citySlug,
            'cityName'   => $cityName,
            'total'      => $total,
            'pagination' => [
                'currentPage'  => $page,
                'totalPages'   => max(1, (int) ceil($total / self::PER_PAGE)),
                'totalItems'   => $total,
                'itemsPerPage' => self::PER_PAGE,
                'routeName'    => 'app_school_directory_city',
                'routeParams'  => ['citySlug' => $citySlug],
            ],
        ]);
    }

    private function buildMapJson(array $mapData): string
    {
        $items = [];
        foreach ($mapData as $school) {
            /** @var School $school */
            if (!$school->getAddressLat() || !$school->getAddressLng()) {
                continue;
            }
            $items[] = [
                'slug' => $school->getCurrentSlug(),
                'name' => $school->getName(),
                'lat'  => (float) $school->getAddressLat(),
                'lng'  => (float) $school->getAddressLng(),
                'addr' => $school->getAddressText() ?? '',
                'url'  => $this->generateUrl('app_school_page', [
                    'citySlug'   => $school->getCitySlug() ?? '',
                    'schoolSlug' => $school->getCurrentSlug(),
                ]),
            ];
        }

        return (string) json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
