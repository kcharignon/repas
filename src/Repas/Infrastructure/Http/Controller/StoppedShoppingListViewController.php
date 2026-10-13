<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Command\StoppedShoppingList\StoppedShoppingListCommand;
use Repas\Repas\Application\Query\ListShoppingLists\ListShoppingListsQuery;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class StoppedShoppingListViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/shopping-list/{id}/stopped', name: 'view_shopping_list_stopped', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('SHOPPING_LIST_OWNER', 'id')]
    public function __invoke(string $id): JsonResponse
    {
        $currentUser = $this->getUser();
        assert($currentUser instanceof AuthenticatedUser);

        $command = new StoppedShoppingListCommand($id);
        $this->commandBus->dispatch($command);

        $shoppingLists = $this->queryBus->ask(new ListShoppingListsQuery($currentUser->getId()));
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
