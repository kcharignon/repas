<?php

namespace Repas\Drive\Infrastructure\Http\Api\MarkLinesInBasket;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Drive\Application\Command\MarkLinesInBasket\MarkLinesInBasketCommand;
use Repas\Drive\Infrastructure\Http\DriveRateLimit;
use Repas\Drive\Infrastructure\Http\RateLimitedPerUser;
use Repas\Drive\Infrastructure\Http\RequiresDrive;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[RequiresDrive]
#[RateLimitedPerUser(DriveRateLimit::WRITE)]
final class MarkLinesInBasketApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/drive/plans/{shoppingListId}/basket-lines', name: 'api_mark_lines_in_basket', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('SHOPPING_LIST_OWNER', 'shoppingListId')]
    #[OA\Post(summary: "Coche, dans la liste envoyée au drive, les lignes dont l'agent a mis l'article au panier de l'enseigne (DRV-745). C'est la seule écriture qui coche une ligne d'une liste envoyée : réservée au propriétaire, jamais un décochage, idempotente (une ligne déjà cochée le reste, un slug absent de la liste est ignoré). La version de la liste ne change pas et la dernière ligne cochée ne termine pas la liste : « Terminer la liste » reste un geste explicite")]
    #[OA\Tag(name: 'Drive')]
    #[OA\Parameter(name: 'shoppingListId', in: 'path', required: true, description: 'Identifiant de la liste de courses (statut `SENT`)', schema: new OA\Schema(type: 'string'), example: '0b0a8c4e-6b0e-4e0a-9d52-2c3f7a1d9e10')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: MarkLinesInBasketPayload::class)))]
    #[OA\Response(response: 204, description: 'Lignes cochées (ou déjà cochées, ou absentes de la liste)')]
    #[OA\Response(response: 403, description: "La liste appartient à un autre utilisateur, administrateur compris (message `SHOPPING_LIST_FORBIDDEN`)")]
    #[OA\Response(response: 404, description: 'Fonction drive coupée (message `DRIVE_DISABLED`), ou liste inconnue')]
    #[OA\Response(response: 409, description: "La liste n'est pas envoyée au drive : rouverte ou terminée entre-temps (message `SHOPPING_LIST_NOT_SENT`)")]
    #[OA\Response(response: 422, description: 'Corps invalide : `ingredientSlugs` absent, vide, de plus de 1 000 éléments, avec un doublon ou un slug mal formé')]
    #[OA\Response(response: 429, ref: '#/components/responses/DriveWriteRateLimited')]
    public function __invoke(
        #[CurrentUser] AuthenticatedUser $user,
        string $shoppingListId,
        #[MapRequestPayload] MarkLinesInBasketPayload $payload,
    ): Response {
        $this->commandBus->dispatch(new MarkLinesInBasketCommand($user->getId(), $shoppingListId, array_values($payload->ingredientSlugs)));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
