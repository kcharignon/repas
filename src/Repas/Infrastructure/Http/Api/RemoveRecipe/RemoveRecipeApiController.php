<?php

namespace Repas\Repas\Infrastructure\Http\Api\RemoveRecipe;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\RemoveRecipe\RemoveRecipeCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Infrastructure\Http\EntityVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class RemoveRecipeApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/recipes/{id}', name: 'api_remove_recipe', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('RECIPE_OWNER', 'id')]
    #[OA\Delete(summary: "Supprime une recette de l'utilisateur, avec ses lignes")]
    #[OA\Tag(name: 'Recipes')]
    #[OA\Parameter(ref: '#/components/parameters/IfMatch')]
    #[OA\Response(response: 204, description: 'Recette supprimée')]
    #[OA\Response(response: 403, description: "La recette appartient à un autre utilisateur (la recette secrète d'un autre est inconnue pour tous, administrateur compris : `404`)")]
    #[OA\Response(response: 404, description: 'Recette inconnue')]
    #[OA\Response(response: 409, description: "La recette est présente dans une liste de courses")]
    #[OA\Response(response: 412, description: "La version de la recette n'est plus celle de `If-Match` : elle a été modifiée depuis sa lecture")]
    public function __invoke(string $id, Request $request): Response
    {
        $this->commandBus->dispatch(new RemoveRecipeCommand($id, EntityVersion::expected($request)));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
