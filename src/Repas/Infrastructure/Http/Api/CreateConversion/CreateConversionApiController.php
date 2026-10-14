<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateConversion;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\CreateConversion\CreateConversionCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class CreateConversionApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/conversions', name: 'api_create_conversion', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Post(summary: 'Crée une conversion entre deux unités (administrateur), propre à un ingrédient ou commune à tous')]
    #[OA\Tag(name: 'Conversions')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ConversionPayload::class)))]
    #[OA\Response(response: 201, description: 'Conversion créée', headers: [new OA\Header(header: 'Location', description: 'URL de la conversion créée', schema: new OA\Schema(type: 'string'))])]
    #[OA\Response(response: 403, description: 'Réservé aux administrateurs')]
    #[OA\Response(response: 404, description: 'Unité ou ingrédient inconnu')]
    #[OA\Response(response: 422, description: 'Corps de la requête invalide')]
    public function __invoke(#[MapRequestPayload] ConversionPayload $payload): Response
    {
        $id = UuidGenerator::new();
        $this->commandBus->dispatch(new CreateConversionCommand(
            id: $id,
            startUnitSlug: $payload->startUnitSlug,
            endUnitSlug: $payload->endUnitSlug,
            coefficient: $payload->coefficient,
            ingredientSlug: $payload->ingredientSlug,
        ));

        return new Response(status: Response::HTTP_CREATED, headers: ['Location' => $this->generateUrl('api_get_conversion', ['id' => $id])]);
    }
}
