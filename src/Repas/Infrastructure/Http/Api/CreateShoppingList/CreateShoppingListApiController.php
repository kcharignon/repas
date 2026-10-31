<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateShoppingList;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class CreateShoppingListApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-lists', name: 'api_create_shopping_list', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(
        summary: "Crée une nouvelle liste active ; la liste en cours du même type passe en pause et les listes terminées sont supprimées",
        description: "Sans corps (ou sans `typeId`), la liste principale : l'éventuelle liste principale en cours passe en pause, comme avant. Avec `typeId`, une liste de ce type, nommée comme lui : seule la liste en cours de ce type passe en pause, la liste principale et les autres types ne bougent pas (ADR 0036).",
    )]
    #[OA\RequestBody(required: false, content: new OA\JsonContent(ref: new Model(type: CreateShoppingListPayload::class)))]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Response(response: 201, description: 'Liste créée', headers: [new OA\Header(header: 'Location', description: 'URL de la liste créée', schema: new OA\Schema(type: 'string'))])]
    #[OA\Response(response: 404, description: "Le type n'est pas à l'utilisateur ou n'existe pas (`LIST_TYPE_NOT_FOUND`) : rien n'est écrit")]
    #[OA\Response(response: 422, description: "Corps invalide (`typeId` n'est pas un texte), ou le plafond de listes est atteint")]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user, #[MapRequestPayload] ?CreateShoppingListPayload $payload = null): Response
    {
        $shoppingListId = UuidGenerator::new();
        $this->commandBus->dispatch(new CreateShoppingListCommand($shoppingListId, $user->getId(), $payload?->typeId));

        return new Response(status: Response::HTTP_CREATED, headers: ['Location' => $this->generateUrl('api_get_shopping_list', ['id' => $shoppingListId])]);
    }
}
