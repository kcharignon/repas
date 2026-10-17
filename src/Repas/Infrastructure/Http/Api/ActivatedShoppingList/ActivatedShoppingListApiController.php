<?php

namespace Repas\Repas\Infrastructure\Http\Api\ActivatedShoppingList;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\ActivatedShoppingList\ActivatedShoppingListCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ActivatedShoppingListApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-lists/{id}/activate', name: 'api_activate_shopping_list', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('SHOPPING_LIST_OWNER', 'id')]
    #[OA\Post(summary: 'Réactive une liste de courses : l\'éventuelle liste active de l\'utilisateur passe en pause')]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Response(response: 204, description: 'Liste réactivée')]
    #[OA\Response(response: 403, description: "La ressource appartient à un autre utilisateur")]
    #[OA\Response(response: 404, description: 'Ressource inconnue')]
    #[OA\Response(response: 409, description: 'La liste est envoyée au drive : elle n\'est plus modifiable (message `SHOPPING_LIST_SENT`)')]
    public function __invoke(string $id): Response
    {
        $this->commandBus->dispatch(new ActivatedShoppingListCommand($id));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
