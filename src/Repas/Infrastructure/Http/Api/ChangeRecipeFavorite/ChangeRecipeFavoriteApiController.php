<?php

namespace Repas\Repas\Infrastructure\Http\Api\ChangeRecipeFavorite;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\ChangeRecipeFavorite\ChangeRecipeFavoriteCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Infrastructure\Http\EntityVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ChangeRecipeFavoriteApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/recipes/{id}/favorite', name: 'api_change_recipe_favorite', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('RECIPE_OWNER', 'id')]
    #[OA\Put(
        summary: "Met une recette de l'utilisateur en favoris, ou l'en retire",
        description: "Une recette favorite est dans la liste « Favoris » de son auteur (`GET /api/v1/recipes/favorites`) en plus de son type. Seul l'auteur change le choix : un administrateur qui peut modifier la recette d'un autre reçoit `403` `RECIPE_FAVORITE_FORBIDDEN` s'il envoie une autre valeur que l'actuelle. Le choix suit la version de la recette (`ETag`, `If-Match`).",
    )]
    #[OA\Tag(name: 'Recipes')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RecipeFavoritePayload::class)))]
    #[OA\Parameter(ref: '#/components/parameters/IfMatch')]
    #[OA\Response(response: 204, description: 'Choix enregistré')]
    #[OA\Response(response: 403, description: "La recette appartient à un autre utilisateur (la recette secrète d'un autre est inconnue pour tous, administrateur compris : `404`)")]
    #[OA\Response(response: 404, description: 'Recette inconnue')]
    #[OA\Response(response: 412, description: "La version de la recette n'est plus celle de `If-Match` : elle a été modifiée depuis sa lecture")]
    #[OA\Response(response: 422, description: "Corps invalide (`favorite` n'est pas un booléen JSON)")]
    public function __invoke(string $id, #[MapRequestPayload] RecipeFavoritePayload $payload, Request $request, #[CurrentUser] AuthenticatedUser $user): Response
    {
        $this->commandBus->dispatch(new ChangeRecipeFavoriteCommand(
            recipeId: $id,
            userId: $user->getId(),
            // Le corps est déjà jugé un booléen JSON
            favorite: $payload->favorite === true,
            expectedVersion: EntityVersion::expected($request),
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
