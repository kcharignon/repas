<?php

namespace Repas\Repas\Infrastructure\Http\Api\UpdateRecipe;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Repas\Infrastructure\Http\Api\CreateRecipe\RecipePayload;
use Repas\Repas\Infrastructure\Http\Api\CreateRecipe\RecipeRowPayload;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Infrastructure\Http\EntityVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
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
    #[OA\Put(summary: "Remplace une recette de l'utilisateur : nom, personnes, type et lignes (les lignes absentes du corps sont supprimées). Un ancien slug est remplacé par celui de l'ingrédient actuel de l'auteur, comme à la création", description: "Le champ facultatif `secret` rend la recette secrète (seul son auteur la voit) ou de nouveau visible : absent ou `null`, il reste inchangé. Seul l'auteur le change. Les copies déjà faites par d'autres restent à eux.")]
    #[OA\Tag(name: 'Recipes')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RecipePayload::class)))]
    #[OA\Parameter(ref: '#/components/parameters/IfMatch')]
    #[OA\Response(response: 204, description: 'Recette modifiée')]
    #[OA\Response(response: 403, description: "La recette appartient à un autre utilisateur (la recette secrète d'un autre, que son auteur seul voit, est inconnue pour tous, administrateur compris : `404`). `RECIPE_SECRET_FORBIDDEN` : un administrateur qui modifie la recette ordinaire d'un autre envoie un `secret` différent de l'actuel, rien n'est écrit")]
    #[OA\Response(response: 404, description: "Recette, type, ingrédient ou unité inconnu (un ingrédient personnalisé d'un autre utilisateur l'est aussi)")]
    #[OA\Response(response: 422, description: 'Corps de la requête invalide (`secret` n\'est pas un booléen JSON)')]
    #[OA\Response(response: 412, description: "La version de la recette n'est plus celle de `If-Match` : elle a été modifiée depuis sa lecture")]
    public function __invoke(string $id, #[MapRequestPayload] RecipePayload $payload, Request $request, #[CurrentUser] AuthenticatedUser $user): Response
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
            expectedVersion: EntityVersion::expected($request),
            // Absent ou `null` : inchangé (un ancien client ne remet jamais le choix à zéro) ; le corps est déjà jugé un booléen ou `null`
            secret: is_bool($payload->secret) ? $payload->secret : null,
            userId: $user->getId(),
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
