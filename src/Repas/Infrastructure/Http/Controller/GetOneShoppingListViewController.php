<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Query\GetShoppingList\GetShoppingListQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GetOneShoppingListViewController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/shopping-list/{id}', name: 'view_one_shopping_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('SHOPPING_LIST_OWNER', 'id')]
    public function __invoke(string $id): Response
    {
        $shoppingList = $this->queryBus->ask(new GetShoppingListQuery($id));

        return $this->render('@Repas/ShoppingList/shopping_list.html.twig', [
            'shoppingList' => $shoppingList,
        ]);
    }
}
