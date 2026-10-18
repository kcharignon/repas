<?php

namespace Repas\Repas\Infrastructure\Http\Api\ReopenShoppingList;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\ReopenShoppingList\ReopenShoppingListCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Infrastructure\Http\EntityVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Volontairement SANS `#[RequiresDrive]` (DRV-613, constat C3 de la revue de sécurité, ADR 0023 §9) : rouvrir une liste
 * n'appelle rien de l'enseigne et ne dépend pas de l'interrupteur `DRIVE_ENABLED`. Une coupure d'urgence (l'enseigne change son
 * site) ne doit pas laisser les utilisateurs avec une liste figée `SENT` : ils la rouvrent eux-mêmes. La sécurité (`ROLE_USER`) et
 * le voter propriétaire restent. `send`, lui, reste derrière l'interrupteur. Le garde-fou
 * `DriveRoutesAreGuardedBySwitchTest` garde cette exception dans une liste explicite.
 */
final class ReopenShoppingListApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-lists/{id}/reopen', name: 'api_reopen_shopping_list', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('SHOPPING_LIST_OWNER', 'id')]
    #[OA\Post(summary: 'Rouvre une liste envoyée au drive : elle redevient active et modifiable, l\'éventuelle autre liste active de l\'utilisateur passe en pause. Reste disponible quand la fonction drive est coupée (`DRIVE_ENABLED` faux), contrairement à l\'envoi')]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Parameter(ref: '#/components/parameters/IfMatch')]
    #[OA\Response(response: 204, description: 'Liste rouverte')]
    #[OA\Response(response: 403, description: "La ressource appartient à un autre utilisateur")]
    #[OA\Response(response: 404, description: 'Liste inconnue')]
    #[OA\Response(response: 409, description: "La liste n'est pas envoyée (message `SHOPPING_LIST_NOT_SENT`), ou elle a été enregistrée entre-temps par une autre requête")]
    #[OA\Response(response: 412, description: "La version de la liste n'est plus celle de `If-Match` : elle a été modifiée depuis sa lecture")]
    public function __invoke(string $id, Request $request): Response
    {
        $this->commandBus->dispatch(new ReopenShoppingListCommand($id, EntityVersion::expected($request)));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
