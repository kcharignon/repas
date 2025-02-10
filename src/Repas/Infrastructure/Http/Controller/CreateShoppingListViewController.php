<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\CreateShoppingList\CreateShoppingListCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class CreateShoppingListViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/shopping-list/create', name: 'view_shopping_list_create', methods: ['GET','POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(): Response
    {
        $userConnected = $this->getUser();
        assert($userConnected instanceof User);
        $shoppingListId = UuidGenerator::new();
        $command = new CreateShoppingListCommand($shoppingListId, $userConnected->getId());
        $this->commandBus->dispatch($command);

        return $this->redirectToRoute('view_one_shopping_list', ['id' => $shoppingListId]);
    }
}
