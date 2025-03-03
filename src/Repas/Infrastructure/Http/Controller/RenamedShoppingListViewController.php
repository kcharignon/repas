<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\UpdateShoppingList\UpdateShoppingListCommand;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class RenamedShoppingListViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly ShoppingListRepository $shoppingListRepository,
    ) {
    }

    #[Route(path: '/shopping-list/{shoppingListId}/rename', name: 'view_shopping_list_rename', methods: ['POST'])]
    #[IsGranted("ROLE_USER")]
    #[IsGranted("SHOPPING_LIST_OWNER", 'shoppingListId')]
    public function __invoke(string $shoppingListId, Request $request): JsonResponse
    {
        $newName = $request->query->get("newName");
        $newName = strlen($newName) > 0 ? $newName : null;

        $command = new UpdateShoppingListCommand($shoppingListId, $newName);
        $this->commandBus->dispatch($command);

        $shoppingList = $this->shoppingListRepository->findOneById($command->shoppingListId);
        return new JsonResponse([
            "status" => "success",
            "views" => [[
                "selector" => "#shopping-list-card-header",
                "html" => $this->renderView("@Repas/ShoppingList/_shopping_list_card_header.html.twig", [
                    "shoppingList" => $shoppingList
                ]),
            ]]
        ]);
    }
}
