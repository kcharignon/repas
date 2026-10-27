<?php

namespace Repas\Repas\Infrastructure\Http\Api\DeleteIngredientConversion;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\DeleteIngredientConversion\DeleteIngredientConversionCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DeleteIngredientConversionApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/ingredients/{slug}/conversions/{conversionId}', name: 'api_delete_ingredient_conversion', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('INGREDIENT_OWNER', 'slug')]
    #[OA\Delete(
        summary: "Supprime une conversion PROPRE d'un ingrédient de l'utilisateur",
        description: "Seul le propriétaire de l'ingrédient la supprime, un administrateur compris. Une conversion universelle ne se supprime jamais (404). Refusé (422 `CONVERSION_REQUIRED_BY_INGREDIENT`, rien n'est écrit) si l'unité des recettes de l'ingrédient ne se convertirait plus en son unité d'achat. Les lignes de recettes qui ne se convertissent plus sont signalées par `convertible: false` à la lecture de la recette.",
    )]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\Response(response: 204, description: 'Conversion supprimée')]
    #[OA\Response(response: 403, description: "L'ingrédient appartient à un autre utilisateur (un administrateur n'a pas de passe-droit)")]
    #[OA\Response(response: 404, description: 'Ingrédient inconnu, ou conversion inconnue, universelle ou propre à un autre ingrédient')]
    #[OA\Response(response: 422, description: "Conversion nécessaire à l'ingrédient : sans elle, son unité des recettes ne se convertirait plus en son unité d'achat (`CONVERSION_REQUIRED_BY_INGREDIENT`)")]
    public function __invoke(string $slug, string $conversionId, #[CurrentUser] AuthenticatedUser $user): Response
    {
        $this->commandBus->dispatch(new DeleteIngredientConversionCommand(
            conversionId: $conversionId,
            ingredientSlug: $slug,
            userId: $user->getId(),
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
