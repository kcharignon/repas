<?php

namespace Repas\Repas\Infrastructure\Http\Api\GetConversion;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\GetConversion\GetConversionQuery;
use Repas\Repas\Application\Query\ListConversions\ConversionDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetConversionApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/conversions/{id}', name: 'api_get_conversion', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Get(summary: 'Détail d\'une conversion entre unités (administrateur)')]
    #[OA\Tag(name: 'Conversions')]
    #[OA\Response(response: 200, description: 'Une conversion', content: new OA\JsonContent(ref: new Model(type: ConversionDTO::class)))]
    #[OA\Response(response: 403, description: 'Réservé aux administrateurs')]
    #[OA\Response(response: 404, description: 'Conversion inconnue')]
    public function __invoke(string $id): JsonResponse
    {
        /** @var ConversionDTO $conversion */
        $conversion = $this->queryBus->ask(new GetConversionQuery($id));

        return new JsonResponse($conversion);
    }
}
