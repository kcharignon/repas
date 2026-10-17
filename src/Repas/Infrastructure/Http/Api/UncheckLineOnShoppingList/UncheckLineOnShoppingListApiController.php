<?php

namespace Repas\Repas\Infrastructure\Http\Api\UncheckLineOnShoppingList;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\UncheckLineOnShoppingList\UncheckLineOnShoppingListCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class UncheckLineOnShoppingListApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-list-rows/{id}/uncheck', name: 'api_uncheck_shopping_list_row', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('SHOPPING_LIST_ROW_OWNER', 'id')]
    #[OA\Post(summary: 'Décoche une ligne de courses ; une liste terminée redevient active')]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Response(response: 204, description: 'Ligne décochée')]
    #[OA\Response(response: 403, description: "La ressource appartient à un autre utilisateur")]
    #[OA\Response(response: 404, description: 'Ressource inconnue')]
    #[OA\Response(response: 409, description: 'La liste est envoyée au drive : elle n\'est plus modifiable (message `SHOPPING_LIST_SENT`)')]
    public function __invoke(string $id): Response
    {
        $this->commandBus->dispatch(new UncheckLineOnShoppingListCommand($id));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
