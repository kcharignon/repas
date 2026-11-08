<?php

namespace Repas\Repas\Infrastructure\Http\Api\RemoveIngredientParent;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\RemoveIngredientParent\RemoveIngredientParentCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class RemoveIngredientParentApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/ingredients/{slug}/parent', name: 'api_remove_ingredient_parent', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('INGREDIENT_OWNER', 'slug')]
    #[OA\Delete(
        summary: "Recolle une partie : l'ingrédient redevient un ingrédient ordinaire (sans parent ni ratio)",
        description: "Seul le propriétaire de l'ingrédient le recolle, un administrateur compris (`403` pour l'ingrédient d'un autre). Idempotent : un ingrédient qui n'est pas une partie répond `204` sans rien changer. Toujours permis, même quand une liste de courses contient l'ingrédient.",
    )]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\Response(response: 204, description: "L'ingrédient est ordinaire (il l'était déjà, ou il vient de l'être)")]
    #[OA\Response(response: 403, description: "L'ingrédient appartient à un autre utilisateur (un administrateur n'a pas de passe-droit)")]
    #[OA\Response(response: 404, description: 'Ingrédient inconnu')]
    public function __invoke(string $slug, #[CurrentUser] AuthenticatedUser $user): Response
    {
        $this->commandBus->dispatch(new RemoveIngredientParentCommand(ingredientSlug: $slug, userId: $user->getId()));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
