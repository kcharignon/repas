<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListUnits;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListUnits\ListUnitsQuery;
use Repas\Repas\Application\Query\ListUnits\UnitDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListUnitsApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/units', name: 'api_list_units', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: 'Liste des unités, triées par slug')]
    #[OA\Tag(name: 'Units')]
    #[OA\Response(
        response: 200,
        description: 'Liste des unités',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'member', type: 'array', items: new OA\Items(ref: new Model(type: UnitDTO::class))),
            new OA\Property(property: 'totalItems', type: 'integer', example: 12),
        ]),
    )]
    public function __invoke(): JsonResponse
    {
        /** @var list<UnitDTO> $units */
        $units = $this->queryBus->ask(new ListUnitsQuery());

        return new JsonResponse(['member' => $units, 'totalItems' => count($units)]);
    }
}
