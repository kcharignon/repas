<?php

namespace Repas\Repas\Infrastructure\Http\Api\RemoveIngredient;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\RemoveIngredient\RemoveIngredientCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class RemoveIngredientApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/ingredients/{slug}', name: 'api_remove_ingredient', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('INGREDIENT_OWNER', 'slug')]
    #[OA\Delete(summary: "Supprime un ingrédient de l'utilisateur, avec ses conversions (un ingrédient commun n'est supprimable que par un administrateur)")]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\Response(response: 204, description: 'Ingrédient supprimé')]
    #[OA\Response(response: 403, description: "L'ingrédient appartient à un autre utilisateur, ou il est commun")]
    #[OA\Response(response: 404, description: 'Ingrédient inconnu')]
    #[OA\Response(response: 409, description: 'Ingrédient présent dans une recette ou une liste de courses')]
    public function __invoke(string $slug): Response
    {
        $this->commandBus->dispatch(new RemoveIngredientCommand($slug));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
