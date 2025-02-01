<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\AdvanceShoppingListToShopping\AdvanceShoppingListToShoppingCommand;
use Repas\Repas\Application\RevertShoppingListToPlanning\RevertShoppingListToPlanningCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AdvanceShoppingListToShoppingController extends AbstractController
{


    public function __construct(
        private readonly CommandBusInterface $commandBus,
    )
    {
    }

    #[Route(path: 'shopping-list/{id}/status/shopping', name: 'view_shopping_list_shopping')]
    #[isGranted('ROLE_USER')]
    public function __invoke(string $id): Response
    {
        $command = new AdvanceShoppingListToShoppingCommand($id);

        $shoppingList = $this->commandBus->dispatch($command);
        $html = $this->renderView("@Repas/ShoppingList/_switch_locked.html.twig", ["shoppingList" => $shoppingList]);

        return new JsonResponse([
            'status' => 'success',
            'views' => [
                'selector' => '#switchShoppingListActiveGroup',
                'html' => $html,
            ]
        ]);
    }

}
