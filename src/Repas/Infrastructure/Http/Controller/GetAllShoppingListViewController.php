<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\CreateShoppingList\CreateShoppingListCommand;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GetAllShoppingListViewController extends AbstractController
{
    public function __construct(
        private readonly ShoppingListRepository $shoppingListRepository,
    ) {
    }


    #[Route('/shopping-list', name: 'view_shopping_lists', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(): Response
    {
        $currentUser = $this->getUser();
        assert($currentUser instanceof User);
        $shoppingLists = $this->shoppingListRepository->findByOwner($currentUser);

        return $this->render('@Repas/ShoppingList/shopping_lists.html.twig', [
            'shoppingLists' => $shoppingLists,
        ]);
    }
}
