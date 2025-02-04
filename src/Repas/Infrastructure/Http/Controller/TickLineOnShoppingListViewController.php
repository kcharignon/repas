<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\TickLineOnShoppingList\TickLineOnShoppingListCommand;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Repas\Shared\Application\Service\CommandBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class TickLineOnShoppingListViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly ShoppingListRowRepository $shoppingListRowRepository,
    ) {
    }

    #[Route(path: '/shopping-list/line/{id}/tick', name: 'view_shopping_list_row_tick', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $id): JsonResponse
    {
        $command = new TickLineOnShoppingListCommand(
            shoppingListRowId: $id,
        );

        $this->commandBus->dispatch($command);

        $shoppingListRow = $this->shoppingListRowRepository->findOneById($id);
        $html = $this->renderView('@Repas/ShoppingList/_row.html.twig', [
            'row' => $shoppingListRow,
        ]);

        return new JsonResponse([
            'views' => [
                [
                    'selector' => "#shopping_list_row_{$id}",
                    'html' => $html,
                ],
            ]
        ]);
    }

}
