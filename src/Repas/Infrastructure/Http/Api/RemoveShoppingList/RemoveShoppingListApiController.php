<?php

namespace Repas\Repas\Infrastructure\Http\Api\RemoveShoppingList;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\RemoveShoppingList\RemoveShoppingListCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class RemoveShoppingListApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-lists/{id}', name: 'api_remove_shopping_list', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('SHOPPING_LIST_OWNER', 'id')]
    #[OA\Delete(summary: 'Supprime une liste de courses')]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Response(response: 204, description: 'Liste supprimée')]
    #[OA\Response(response: 403, description: "La ressource appartient à un autre utilisateur")]
    #[OA\Response(response: 404, description: 'Ressource inconnue')]
    public function __invoke(string $id): Response
    {
        $this->commandBus->dispatch(new RemoveShoppingListCommand($id));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
