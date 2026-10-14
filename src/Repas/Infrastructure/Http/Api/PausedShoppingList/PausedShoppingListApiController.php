<?php

namespace Repas\Repas\Infrastructure\Http\Api\PausedShoppingList;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\PausedShoppingList\PausedShoppingListCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class PausedShoppingListApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-lists/{id}/pause', name: 'api_pause_shopping_list', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('SHOPPING_LIST_OWNER', 'id')]
    #[OA\Post(summary: 'Met une liste de courses en pause')]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Response(response: 204, description: 'Liste mise en pause')]
    #[OA\Response(response: 403, description: "La ressource appartient à un autre utilisateur")]
    #[OA\Response(response: 404, description: 'Ressource inconnue')]
    public function __invoke(string $id): Response
    {
        $this->commandBus->dispatch(new PausedShoppingListCommand($id));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
