<?php

namespace Repas\Repas\Infrastructure\Http\Api\UpdateIngredientConversion;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\UpdateIngredientConversion\UpdateIngredientConversionCommand;
use Repas\Repas\Infrastructure\Http\Api\CreateIngredientConversion\IngredientConversionPayload;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class UpdateIngredientConversionApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/ingredients/{slug}/conversions/{conversionId}', name: 'api_update_ingredient_conversion', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('INGREDIENT_OWNER', 'slug')]
    #[OA\Put(
        summary: "Remplace une conversion PROPRE d'un ingrédient de l'utilisateur",
        description: "Seul le propriétaire de l'ingrédient la modifie, un administrateur compris. Une conversion universelle ou d'un autre ingrédient est inconnue (404). Refusé (422 `CONVERSION_REQUIRED_BY_INGREDIENT`, rien n'est écrit) si l'unité des recettes de l'ingrédient ne se convertissait plus alors en son unité d'achat.",
    )]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: IngredientConversionPayload::class)))]
    #[OA\Response(response: 204, description: 'Conversion modifiée')]
    #[OA\Response(response: 403, description: "L'ingrédient appartient à un autre utilisateur (un administrateur n'a pas de passe-droit)")]
    #[OA\Response(response: 404, description: "Ingrédient ou unité inconnu, ou conversion inconnue, universelle ou propre à un autre ingrédient")]
    #[OA\Response(response: 409, description: "L'ingrédient a déjà une autre conversion propre entre ces deux unités, dans un sens ou dans l'autre (`CONVERSION_ALREADY_EXISTS`)")]
    #[OA\Response(response: 422, description: "Corps invalide (unités identiques `CONVERSION_SAME_UNIT`, coefficient qui n'est pas un nombre strictement positif), ou conversion nécessaire à l'ingrédient (`CONVERSION_REQUIRED_BY_INGREDIENT`)")]
    public function __invoke(string $slug, string $conversionId, #[MapRequestPayload] IngredientConversionPayload $payload, #[CurrentUser] AuthenticatedUser $user): Response
    {
        $this->commandBus->dispatch(new UpdateIngredientConversionCommand(
            conversionId: $conversionId,
            ingredientSlug: $slug,
            startUnitSlug: $payload->startUnitSlug,
            endUnitSlug: $payload->endUnitSlug,
            coefficient: $payload->coefficient,
            userId: $user->getId(),
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
