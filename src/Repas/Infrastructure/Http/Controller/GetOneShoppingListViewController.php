<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GetOneShoppingListViewController extends AbstractController
{
    public function __construct(
        private readonly ShoppingListRepository $shoppingListRepository,
    ) {
    }

    #[Route('/shopping-list/{id}', name: 'view_one_shopping_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $id): Response
    {
        $shoppingList = $this->shoppingListRepository->findOneById($id);
        return $this->render('@Repas/ShoppingList/shopping_list.html.twig', [
            'shoppingList' => $shoppingList,
        ]);
    }

}
