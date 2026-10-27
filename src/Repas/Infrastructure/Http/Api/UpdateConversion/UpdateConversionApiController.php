<?php

namespace Repas\Repas\Infrastructure\Http\Api\UpdateConversion;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\UpdateConversion\UpdateConversionCommand;
use Repas\Repas\Infrastructure\Http\Api\CreateConversion\ConversionPayload;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class UpdateConversionApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/conversions/{id}', name: 'api_update_conversion', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Put(summary: 'Remplace une conversion entre unités (administrateur) : une conversion générale ou celle de l\'un de SES ingrédients')]
    #[OA\Tag(name: 'Conversions')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ConversionPayload::class)))]
    #[OA\Response(response: 204, description: 'Conversion modifiée')]
    #[OA\Response(response: 403, description: 'Réservé aux administrateurs')]
    #[OA\Response(response: 404, description: 'Conversion, unité ou ingrédient inconnu, ou conversion ou ingrédient d\'un autre utilisateur (un administrateur n\'a pas de passe-droit)')]
    #[OA\Response(response: 422, description: 'Corps de la requête invalide, ou conversion universelle (sans ingrédient) dont le corps change la paire d\'unités ou l\'ingrédient : seul son coefficient se corrige (`UNIVERSAL_CONVERSION_IS_FIXED`), ou rattachement à un ingrédient qui a déjà 50 conversions propres (`CONVERSION_LIMIT_REACHED`)')]
    public function __invoke(string $id, #[MapRequestPayload] ConversionPayload $payload, #[CurrentUser] AuthenticatedUser $user): Response
    {
        $this->commandBus->dispatch(new UpdateConversionCommand(
            id: $id,
            startUnitSlug: $payload->startUnitSlug,
            endUnitSlug: $payload->endUnitSlug,
            coefficient: $payload->coefficient,
            ingredientSlug: $payload->ingredientSlug,
            userId: $user->getId(),
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
