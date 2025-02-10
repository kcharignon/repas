<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\UpdateServingMeal\UpdateServingMealCommand;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class UpdateServingMealViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly ShoppingListRepository $shoppingListRepository,
    ) {
    }

    #[Route(path: '/shopping-list/meal/{id}/serving/{serving}', name: 'view_update_serving_meal', methods: ['GET', 'PUT'])]
    #[IsGranted("ROLE_USER")]
    #[IsGranted("MEAL_OWNER", "id")]
    public function __invoke(string $id, int $serving): JsonResponse
    {
        $command = new UpdateServingMealCommand($id, $serving);
        $this->commandBus->dispatch($command);

        $shoppingList = $this->shoppingListRepository->findOneByMealId($id);
        $html = $this->renderView('@Repas/ShoppingList/_ingredients_column.html.twig', [
            'shoppingList' => $shoppingList,
        ]);

        return new JsonResponse([
            "views" => [
                [
                    "selector" => "#shoppingListIngredientsColumn",
                    "html" => $html,
                ]
            ]
        ]);
    }

}
