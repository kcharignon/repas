<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListConversions;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListConversions\ConversionDTO;
use Repas\Repas\Application\Query\ListConversions\ListConversionsQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListConversionsApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/conversions', name: 'api_list_conversions', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Get(summary: 'Conversions entre unités (administrateur), triées par ingrédient puis par unité de départ')]
    #[OA\Tag(name: 'Conversions')]
    #[OA\Response(
        response: 200,
        description: 'Liste des conversions',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'member', type: 'array', items: new OA\Items(ref: new Model(type: ConversionDTO::class))),
            new OA\Property(property: 'totalItems', type: 'integer', example: 67),
        ]),
    )]
    #[OA\Response(response: 403, description: 'Réservé aux administrateurs')]
    public function __invoke(): JsonResponse
    {
        /** @var list<ConversionDTO> $conversions */
        $conversions = $this->queryBus->ask(new ListConversionsQuery());

        return new JsonResponse(['member' => $conversions, 'totalItems' => count($conversions)]);
    }
}
