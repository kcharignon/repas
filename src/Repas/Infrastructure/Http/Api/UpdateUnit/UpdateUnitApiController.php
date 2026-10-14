<?php

namespace Repas\Repas\Infrastructure\Http\Api\UpdateUnit;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\UpdateUnit\UpdateUnitCommand;
use Repas\Repas\Infrastructure\Http\Api\CreateUnit\UnitPayload;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class UpdateUnitApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/units/{slug}', name: 'api_update_unit', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Put(summary: "Remplace l'unité (administrateur) ; son slug ne change pas")]
    #[OA\Tag(name: 'Units')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UnitPayload::class)))]
    #[OA\Response(response: 204, description: 'Modifié')]
    #[OA\Response(response: 403, description: 'Réservé aux administrateurs')]
    #[OA\Response(response: 404, description: 'Inconnu')]
    #[OA\Response(response: 422, description: 'Corps de la requête invalide')]
    public function __invoke(string $slug, #[MapRequestPayload] UnitPayload $payload): Response
    {
        $this->commandBus->dispatch(new UpdateUnitCommand(slug: $slug, name: $payload->name, symbol: $payload->symbol));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
