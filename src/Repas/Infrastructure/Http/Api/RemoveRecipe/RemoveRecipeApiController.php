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
    #[OA\Delete(summary: "Supprime une recette de l'utilisateur, avec ses lignes (et le lien vers les recettes qu'elle utilise)", description: "Une recette SANS PROPRIÉTAIRE (la recette Marmiton neutre) n'est supprimée que par un administrateur, pour repartir d'un import raté : son tag Marmiton est libéré (un nouvel import de la même page recrée une neutre neuve, la page est relue), les copies déjà faites restent à leurs auteurs, telles quelles, avec leur lien d'origine. Elle ne se supprime pas tant qu'elle est dans une liste de courses (`409`).")]
    #[OA\Tag(name: 'Recipes')]
    #[OA\Parameter(ref: '#/components/parameters/IfMatch')]
    #[OA\Response(response: 204, description: 'Recette supprimée')]
    #[OA\Response(response: 403, description: "La recette appartient à un autre utilisateur, ou n'a pas de propriétaire (recette Marmiton neutre) et l'utilisateur n'est pas administrateur (la recette secrète d'un autre est inconnue pour tous, administrateur compris : `404`)")]
    #[OA\Response(response: 404, description: 'Recette inconnue')]
    #[OA\Response(response: 409, description: "La recette est présente dans une liste de courses, ou une autre recette l'utilise (`RECIPE_USED_AS_COMPONENT` : il faut d'abord la retirer des recettes qui l'utilisent, `GET /recipes/{id}/used-in` dit lesquelles). Supprimer une recette qui en utilise d'autres efface le lien, jamais les recettes utilisées")]
    #[OA\Response(response: 412, description: "La version de la recette n'est plus celle de `If-Match` : elle a été modifiée depuis sa lecture")]
    public function __invoke(string $id, Request $request): Response
    {
        $this->commandBus->dispatch(new RemoveRecipeCommand($id, EntityVersion::expected($request), $this->isGranted('ROLE_ADMIN')));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
