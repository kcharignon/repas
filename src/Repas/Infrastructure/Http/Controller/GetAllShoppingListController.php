<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\GetAllRecipeType\GetAllRecipeTypeQuery;
use Repas\Repas\Application\GetAllShoppingList\GetAllShoppingListQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GetAllShoppingListController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }


    #[Route('/shopping-list', name: 'view_shopping_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(): Response
    {
        $currentUser = $this->getUser();
        assert($currentUser instanceof User);
        $shoppingLists = $this->queryBus->ask(new GetAllShoppingListQuery($currentUser));

        return $this->render('@Repas/ShoppingList/shopping_list.html.twig', [
            'shoppingLists' => $shoppingLists,
        ]);
    }
}
