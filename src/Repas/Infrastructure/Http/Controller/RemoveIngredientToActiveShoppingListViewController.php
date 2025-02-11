<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\RemoveIngredientToShoppingList\RemoveIngredientToShoppingListCommand;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class RemoveIngredientToActiveShoppingListViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly IngredientRepository $ingredientRepository,
        private readonly ShoppingListRepository $shoppingListRepository,
    ) {
    }

    #[Route(path:'/shopping-list/active/ingredient/{slug}/remove', name: 'view_shopping_list_remove_ingredient', methods: ['POST'])]
    #[IsGranted("ROLE_USER")]
    public function __invoke($slug): JsonResponse
    {
        $connectedUser = $this->getUser();
        assert($connectedUser instanceof User);

        $command = new RemoveIngredientToShoppingListCommand(
            $connectedUser->getId(),
            $slug
        );
        $this->commandBus->dispatch($command);

        $ingredient = $this->ingredientRepository->findOneBySlug($slug);

        $activeShoppingList = $this->shoppingListRepository->findOneActivateByOwner($connectedUser);
        $html = $this->renderView('@Repas/Department/_ingredient_row.html.twig', [
            'ingredient' => $ingredient,
            'shoppingList' => $activeShoppingList,
        ]);

        return new JsonResponse([
            "views" => [
                [
                    "selector" => "#ingredient-row-{$ingredient->getSlug()}",
                    "html" => $html,
                ]
            ]
        ]);
    }

}
