<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\AddIngredientToShoppingList\AddIngredientToShoppingListCommand;
use Repas\Repas\Application\CreateShoppingList\CreateShoppingListCommand;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AddIngredientToActiveShoppingListViewController extends AbstractController
{
    public function __construct(
        private readonly ShoppingListRepository $shoppingListRepository,
        private readonly IngredientRepository $ingredientRepository,
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path:'/shopping-list/active/ingredient/{slug}/add', name: 'view_shopping_list_add_ingredient', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $slug): JsonResponse
    {
        $connectedUser = $this->getUser();
        assert($connectedUser instanceof User);

        // Recuperation de la liste active ou creation d'une nouvelle liste (active)
        $activeShoppingList = $this->shoppingListRepository->findOneActivateByOwner($connectedUser);
        if (!$activeShoppingList instanceof ShoppingList) {
            // Creation d'une nouvelle liste active
            $activeShoppingListId = UuidGenerator::new();
            $command = new CreateShoppingListCommand(
                $activeShoppingListId,
                $connectedUser->getId(),
            );
            $this->commandBus->dispatch($command);
        } else {
            $activeShoppingListId = $activeShoppingList->getId();
        }

        //On Ajoute l'ingrédient dans la liste active
        $command = new AddIngredientToShoppingListCommand(
            $connectedUser->getId(),
            $slug
        );
        $this->commandBus->dispatch($command);

        $ingredient = $this->ingredientRepository->findOneBySlug($slug);

        return new JsonResponse([
            "alerts" => [
                [
                    "status" => "success",
                    "message" => $ingredient->getName()." a été ajouté.",
                ]
            ]
        ]);
    }

}
