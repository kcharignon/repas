<?php

namespace Repas\Repas\Infrastructure\Http\Api\GetUnit;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\GetUnit\GetUnitQuery;
use Repas\Repas\Application\Query\ListUnits\UnitDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetUnitApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/units/{slug}', name: 'api_get_unit', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: 'Détail d\'une unité')]
    #[OA\Tag(name: 'Units')]
    #[OA\Response(
        response: 200,
        description: 'Une unité',
        content: new OA\JsonContent(ref: new Model(type: UnitDTO::class)),
    )]
    #[OA\Response(response: 404, description: 'Inconnu')]
    public function __invoke(string $slug): JsonResponse
    {
        /** @var UnitDTO $item */
        $item = $this->queryBus->ask(new GetUnitQuery($slug));

        return new JsonResponse($item);
    }
}
