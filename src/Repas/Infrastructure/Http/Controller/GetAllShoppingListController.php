<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GetAllShoppingListController extends AbstractController
{
    public function __construct(
        private readonly ShoppingListRepository $shoppingListRepository,
    ) {
    }


    #[Route('/shopping-lists', name: 'view_shopping_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(): Response
    {
        $currentUser = $this->getUser();
        assert($currentUser instanceof User);
        $shoppingLists = $this->shoppingListRepository->findByOwner($currentUser);

        return $this->render('@Repas/ShoppingList/shopping_list.html.twig', [
            'shoppingLists' => $shoppingLists,
        ]);
    }
}
