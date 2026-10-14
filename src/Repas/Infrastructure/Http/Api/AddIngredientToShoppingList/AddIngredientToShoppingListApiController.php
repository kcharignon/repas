<?php

namespace Repas\Repas\Infrastructure\Http\Api\AddIngredientToShoppingList;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\AddIngredientToShoppingList\AddIngredientToShoppingListCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AddIngredientToShoppingListApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-lists/active/ingredients/{slug}/increase', name: 'api_increase_ingredient', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(summary: 'Ajoute 1 à la quantité d\'un ingrédient dans la liste active de l\'utilisateur')]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Response(response: 204, description: 'Quantité augmentée')]
    #[OA\Response(response: 404, description: 'Ressource inconnue')]
    public function __invoke(string $slug, #[CurrentUser] AuthenticatedUser $user): Response
    {
        $this->commandBus->dispatch(new AddIngredientToShoppingListCommand($user->getId(), $slug));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
