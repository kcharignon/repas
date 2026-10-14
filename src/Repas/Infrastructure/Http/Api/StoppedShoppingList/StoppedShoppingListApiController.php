<?php

namespace Repas\Repas\Infrastructure\Http\Api\StoppedShoppingList;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\StoppedShoppingList\StoppedShoppingListCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class StoppedShoppingListApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-lists/{id}/complete', name: 'api_complete_shopping_list', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('SHOPPING_LIST_OWNER', 'id')]
    #[OA\Post(summary: 'Termine une liste de courses (ses statistiques sont ajoutées à celles de l\'utilisateur)')]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Response(response: 204, description: 'Liste terminée')]
    #[OA\Response(response: 403, description: "La ressource appartient à un autre utilisateur")]
    #[OA\Response(response: 404, description: 'Ressource inconnue')]
    public function __invoke(string $id): Response
    {
        $this->commandBus->dispatch(new StoppedShoppingListCommand($id));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
