<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListCommand;
use Repas\Repas\Application\Query\GetCurrentShoppingList\GetCurrentShoppingListQuery;
use Repas\Repas\Application\Query\GetShoppingList\GetShoppingListQuery;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GetActiveShoppingListViewController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route('/shopping-list/active', name: 'view_shopping_list_active', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(): Response
    {
        $currentUser = $this->getUser();
        assert($currentUser instanceof AuthenticatedUser);

        $shoppingList = $this->queryBus->ask(new GetCurrentShoppingListQuery($currentUser->getId()));
        if ($shoppingList === null) {
            // Aucune liste : on en crée une (active) avant de l'afficher
            $shoppingListId = UuidGenerator::new();
            $this->commandBus->dispatch(new CreateShoppingListCommand($shoppingListId, $currentUser->getId()));
            $shoppingList = $this->queryBus->ask(new GetShoppingListQuery($shoppingListId));
        }

        return $this->render('@Repas/ShoppingList/shopping_list.html.twig', [
            'shoppingList' => $shoppingList,
        ]);
    }
}
