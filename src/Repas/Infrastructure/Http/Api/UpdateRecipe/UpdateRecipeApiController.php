<?php

namespace Repas\Repas\Infrastructure\Http\Api\UpdateRecipe;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Repas\Infrastructure\Http\Api\CreateRecipe\RecipePayload;
use Repas\Repas\Infrastructure\Http\Api\CreateRecipe\RecipeRowPayload;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Tool\Tab;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class UpdateRecipeApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/recipes/{id}', name: 'api_update_recipe', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('RECIPE_OWNER', 'id')]
    #[OA\Put(summary: "Remplace une recette de l'utilisateur : nom, personnes, type et lignes (les lignes absentes du corps sont supprimées)")]
    #[OA\Tag(name: 'Recipes')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RecipePayload::class)))]
    #[OA\Response(response: 204, description: 'Recette modifiée')]
    #[OA\Response(response: 403, description: 'La recette appartient à un autre utilisateur')]
    #[OA\Response(response: 404, description: "Recette, type, ingrédient ou unité inconnu (un ingrédient personnalisé d'un autre utilisateur l'est aussi)")]
    #[OA\Response(response: 422, description: 'Corps de la requête invalide')]
    public function __invoke(string $id, #[MapRequestPayload] RecipePayload $payload): Response
    {
        $this->commandBus->dispatch(new UpdateRecipeCommand(
            id: $id,
            name: $payload->name,
            serving: $payload->serving,
            rows: new Tab(
                array_map(static fn(RecipeRowPayload $row) => new UpdateRecipeRowSubCommand($row->ingredientSlug, $row->unitSlug, $row->quantity), $payload->rows),
                UpdateRecipeRowSubCommand::class,
            ),
            typeSlug: $payload->typeSlug,
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
