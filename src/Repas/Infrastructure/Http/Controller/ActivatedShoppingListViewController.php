<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\ActivatedShoppingList\ActivatedShoppingListCommand;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ActivatedShoppingListViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly ShoppingListRepository $shoppingListRepository,
    ) {
    }

    #[Route(path: '/shopping-list/{id}/activated', name: 'view_shopping_list_activated', methods: ['POST'])]
    #[IsGranted('SHOPPING_LIST_OWNER', 'id')]
    public function __invoke(string $id): JsonResponse
    {
        $currentUser = $this->getUser();
        assert($currentUser instanceof User);

        $command = new ActivatedShoppingListCommand($id);
        $this->commandBus->dispatch($command);

        $shoppingLists = $this->shoppingListRepository->findByOwner($currentUser);
        $html = $this->renderView('@Repas/ShoppingList/_shopping_list_list.html.twig', [
            'shoppingLists' => $shoppingLists,
        ]);
        return new JsonResponse([
            'views' => [
                [
                    "selector" => "#shopping-list-list",
                    "html" => $html,
                ]
            ]
        ]);
    }
}
