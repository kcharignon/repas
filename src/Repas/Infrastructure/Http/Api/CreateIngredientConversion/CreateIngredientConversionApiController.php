<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateIngredientConversion;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\CreateIngredientConversion\CreateIngredientConversionCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class CreateIngredientConversionApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/ingredients/{slug}/conversions', name: 'api_create_ingredient_conversion', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('INGREDIENT_OWNER', 'slug')]
    #[OA\Post(
        summary: "Ajoute une conversion PROPRE à un ingrédient de l'utilisateur (« 1 unité de départ = coefficient unités d'arrivée »), vers n'importe quelle unité, 50 au plus par ingrédient",
        description: "Seul le propriétaire de l'ingrédient l'écrit, un administrateur compris. Jamais une conversion universelle (l'administrateur les écrit par `/api/v1/conversions`). `Location` est l'adresse de la conversion créée (modifiable et supprimable).",
    )]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: IngredientConversionPayload::class)))]
    #[OA\Response(response: 201, description: 'Conversion créée', headers: [new OA\Header(header: 'Location', description: 'URL de la conversion créée', schema: new OA\Schema(type: 'string'))])]
    #[OA\Response(response: 403, description: "L'ingrédient appartient à un autre utilisateur (un administrateur n'a pas de passe-droit)")]
    #[OA\Response(response: 404, description: 'Ingrédient ou unité inconnu')]
    #[OA\Response(response: 409, description: "L'ingrédient a déjà sa propre conversion entre ces deux unités, dans un sens ou dans l'autre (`CONVERSION_ALREADY_EXISTS`) : on modifie celle qui existe")]
    #[OA\Response(response: 422, description: "Corps invalide : unités identiques (`CONVERSION_SAME_UNIT`), coefficient qui n'est pas un nombre strictement positif, ou l'ingrédient a déjà 50 conversions propres (`CONVERSION_LIMIT_REACHED`)")]
    public function __invoke(string $slug, #[MapRequestPayload] IngredientConversionPayload $payload, #[CurrentUser] AuthenticatedUser $user): Response
    {
        $id = UuidGenerator::new();
        $this->commandBus->dispatch(new CreateIngredientConversionCommand(
            id: $id,
            ingredientSlug: $slug,
            startUnitSlug: $payload->startUnitSlug,
            endUnitSlug: $payload->endUnitSlug,
            coefficient: $payload->coefficient,
            userId: $user->getId(),
        ));

        return new Response(status: Response::HTTP_CREATED, headers: ['Location' => $this->generateUrl('api_update_ingredient_conversion', ['slug' => $slug, 'conversionId' => $id])]);
    }
}
