<?php

namespace Repas\Repas\Infrastructure\Http\Api\ReopenShoppingList;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\ReopenShoppingList\ReopenShoppingListCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Infrastructure\Http\EntityVersion;
use Repas\Shared\Infrastructure\Http\RequiresDrive;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[RequiresDrive]
final class ReopenShoppingListApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-lists/{id}/reopen', name: 'api_reopen_shopping_list', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('SHOPPING_LIST_OWNER', 'id')]
    #[OA\Post(summary: 'Rouvre une liste envoyée au drive : elle redevient active et modifiable, l\'éventuelle autre liste active de l\'utilisateur passe en pause')]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Parameter(ref: '#/components/parameters/IfMatch')]
    #[OA\Response(response: 204, description: 'Liste rouverte')]
    #[OA\Response(response: 403, description: "La ressource appartient à un autre utilisateur")]
    #[OA\Response(response: 404, description: 'Liste inconnue, ou fonction drive coupée (message `DRIVE_DISABLED`)')]
    #[OA\Response(response: 409, description: "La liste n'est pas envoyée (message `SHOPPING_LIST_NOT_SENT`), ou elle a été enregistrée entre-temps par une autre requête")]
    #[OA\Response(response: 412, description: "La version de la liste n'est plus celle de `If-Match` : elle a été modifiée depuis sa lecture")]
    public function __invoke(string $id, Request $request): Response
    {
        $this->commandBus->dispatch(new ReopenShoppingListCommand($id, EntityVersion::expected($request)));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
