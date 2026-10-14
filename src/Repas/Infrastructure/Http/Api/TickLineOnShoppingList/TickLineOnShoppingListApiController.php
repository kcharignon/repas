<?php

namespace Repas\Repas\Infrastructure\Http\Api\TickLineOnShoppingList;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\TickLineOnShoppingList\TickLineOnShoppingListCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class TickLineOnShoppingListApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-list-rows/{id}/tick', name: 'api_tick_shopping_list_row', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('SHOPPING_LIST_ROW_OWNER', 'id')]
    #[OA\Post(summary: 'Coche une ligne de courses ; cocher la dernière ligne termine la liste')]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Response(response: 204, description: 'Ligne cochée')]
    #[OA\Response(response: 403, description: "La ressource appartient à un autre utilisateur")]
    #[OA\Response(response: 404, description: 'Ressource inconnue')]
    public function __invoke(string $id): Response
    {
        $this->commandBus->dispatch(new TickLineOnShoppingListCommand($id));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
