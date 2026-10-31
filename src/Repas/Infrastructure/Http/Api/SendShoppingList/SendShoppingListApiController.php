<?php

namespace Repas\Repas\Infrastructure\Http\Api\SendShoppingList;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\SendShoppingList\SendShoppingListCommand;
use Repas\Repas\Infrastructure\Http\RequiresDriveSending;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Infrastructure\Http\EntityVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[RequiresDriveSending]
final class SendShoppingListApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-lists/{id}/send', name: 'api_send_shopping_list', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('SHOPPING_LIST_OWNER', 'id')]
    #[OA\Post(summary: 'Envoie une liste de courses au drive : elle passe au statut `SENT` et n\'est plus modifiable (sans effet si elle l\'est déjà)')]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Parameter(ref: '#/components/parameters/IfMatch')]
    #[OA\Response(response: 204, description: 'Liste envoyée (ou déjà envoyée)')]
    #[OA\Response(response: 403, description: "La ressource appartient à un autre utilisateur")]
    #[OA\Response(response: 404, description: 'Liste inconnue, ou fonction drive coupée (message `DRIVE_DISABLED`)')]
    #[OA\Response(response: 409, description: "La liste n'est ni active ni déjà envoyée (message `SHOPPING_LIST_NOT_SENDABLE`), ou elle a été enregistrée entre-temps par une autre requête")]
    #[OA\Response(response: 412, description: "La version de la liste n'est plus celle de `If-Match` : elle a été modifiée depuis sa lecture")]
    #[OA\Response(response: 422, description: 'La liste est vide (message `SHOPPING_LIST_EMPTY`)')]
    public function __invoke(string $id, Request $request): Response
    {
        $this->commandBus->dispatch(new SendShoppingListCommand($id, EntityVersion::expected($request)));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
