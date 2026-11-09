<?php

namespace Repas\Repas\Infrastructure\Http\Api\DecreaseShoppingListRowQuantity;

use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\DecreaseShoppingListRowQuantity\DecreaseShoppingListRowQuantityCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Infrastructure\Http\EntityVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DecreaseShoppingListRowQuantityApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-list-rows/{id}/decrease', name: 'api_decrease_shopping_list_row', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('SHOPPING_LIST_ROW_OWNER', 'id')]
    #[OA\Post(summary: 'Retire 1 à la quantité d\'une ligne précise d\'une liste, dans l\'unité d\'achat de la ligne ; la ligne disparaît quand il n\'en reste presque rien (0,4 lu « 1 » disparaît d\'un retrait). C\'est la liste qui porte la ligne qui change (à la différence de `…/active/ingredients/{slug}/decrease`)')]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Parameter(ref: '#/components/parameters/IfMatch')]
    #[OA\Response(response: 204, description: 'Quantité diminuée')]
    #[OA\Response(response: 403, description: "La ressource appartient à un autre utilisateur")]
    #[OA\Response(response: 404, description: 'Ressource inconnue')]
    #[OA\Response(response: 409, description: 'La liste est envoyée au drive : elle n\'est plus modifiable (message `SHOPPING_LIST_SENT`)')]
    #[OA\Response(response: 412, description: "La version de la liste n'est plus celle de `If-Match` : elle a été modifiée depuis sa lecture")]
    public function __invoke(string $id, Request $request): Response
    {
        $this->commandBus->dispatch(new DecreaseShoppingListRowQuantityCommand($id, EntityVersion::expected($request)));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
