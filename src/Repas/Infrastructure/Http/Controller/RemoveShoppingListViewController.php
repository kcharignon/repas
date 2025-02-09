<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\RemoveShoppingList\RemoveShoppingListCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class RemoveShoppingListViewController extends AbstractController
{

    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/shopping-list/{id}', name: 'view_shopping_list_remove', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('SHOPPING_LIST_OWNER', 'id')]
    public function __invoke(string $id): JsonResponse
    {
        $this->commandBus->dispatch(new RemoveShoppingListCommand($id));

        return new JsonResponse([
            "status" => "success",
            "views" => [
                [
                    "selector" => "#shopping-list-$id",
                    "html" => "",
                ]
            ]
        ], 200);
    }
}
