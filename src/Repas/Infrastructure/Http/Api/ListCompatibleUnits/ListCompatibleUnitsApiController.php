<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListCompatibleUnits;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListCompatibleUnits\CompatibleUnitDTO;
use Repas\Repas\Application\Query\ListCompatibleUnits\ListCompatibleUnitsQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListCompatibleUnitsApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/ingredients/{slug}/units', name: 'api_list_compatible_units', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: "Unités compatibles d'un ingrédient, dans l'ordre où elles sont enregistrées")]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\Response(
        response: 200,
        description: 'Unités compatibles',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'member', type: 'array', items: new OA\Items(ref: new Model(type: CompatibleUnitDTO::class))),
            new OA\Property(property: 'totalItems', type: 'integer', example: 3),
        ]),
    )]
    #[OA\Response(response: 404, description: 'Ingrédient inconnu')]
    public function __invoke(string $slug): JsonResponse
    {
        /** @var list<CompatibleUnitDTO> $units */
        $units = $this->queryBus->ask(new ListCompatibleUnitsQuery($slug));

        return new JsonResponse(['member' => $units, 'totalItems' => count($units)]);
    }
}
