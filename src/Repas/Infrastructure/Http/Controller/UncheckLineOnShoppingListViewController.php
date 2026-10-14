<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Command\UncheckLineOnShoppingList\UncheckLineOnShoppingListCommand;
use Repas\Repas\Application\Query\GetShoppingListRow\GetShoppingListRowQuery;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class UncheckLineOnShoppingListViewController extends AbstractController
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/shopping-list/line/{id}/uncheck', name: 'view_shopping_list_row_uncheck', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('SHOPPING_LIST_ROW_OWNER', 'id')]
    public function __invoke(string $id): JsonResponse
    {
        $command = new UncheckLineOnShoppingListCommand($id);

        $this->commandBus->dispatch($command);

        $row = $this->queryBus->ask(new GetShoppingListRowQuery($id));

        $html = $this->renderView('@Repas/ShoppingList/_row.html.twig', [
            'row' => $row,
        ]);

        return new JsonResponse([
            'views' => [
                [
                    "selector" => "#shopping_list_row_{$id}",
                    "html" => $html,
                ],
            ]
        ]);
    }

}
