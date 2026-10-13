<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Query\ListShoppingLists\ListShoppingListsQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GetAllShoppingListViewController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }


    #[Route('/shopping-list', name: 'view_shopping_lists', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(): Response
    {
        $currentUser = $this->getUser();
        assert($currentUser instanceof AuthenticatedUser);
        $shoppingLists = $this->queryBus->ask(new ListShoppingListsQuery($currentUser->getId()));

        return $this->render('@Repas/ShoppingList/shopping_lists.html.twig', [
            'shoppingLists' => $shoppingLists,
        ]);
    }
}
