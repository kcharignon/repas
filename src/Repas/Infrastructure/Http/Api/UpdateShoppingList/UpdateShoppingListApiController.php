<?php

namespace Repas\Repas\Infrastructure\Http\Api\UpdateShoppingList;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\UpdateShoppingList\UpdateShoppingListCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class UpdateShoppingListApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-lists/{id}', name: 'api_update_shopping_list', methods: ['PATCH'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('SHOPPING_LIST_OWNER', 'id')]
    #[OA\Patch(summary: 'Renomme une liste de courses ; `name` absent ou null rétablit le nom par défaut (la date)')]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdateShoppingListPayload::class)))]
    #[OA\Response(response: 204, description: 'Liste renommée')]
    #[OA\Response(response: 403, description: "La ressource appartient à un autre utilisateur")]
    #[OA\Response(response: 404, description: 'Ressource inconnue')]
    #[OA\Response(response: 422, description: 'Corps de la requête invalide')]
    public function __invoke(string $id, #[MapRequestPayload] UpdateShoppingListPayload $payload): Response
    {
        $this->commandBus->dispatch(new UpdateShoppingListCommand($id, $payload->name));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
