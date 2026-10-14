<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateShoppingList;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
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
    #[OA\Post(summary: 'Crée une nouvelle liste active ; l\'éventuelle liste active passe en pause et les listes terminées sont supprimées')]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Response(response: 201, description: 'Liste créée', headers: [new OA\Header(header: 'Location', description: 'URL de la liste créée', schema: new OA\Schema(type: 'string'))])]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user): Response
    {
        $shoppingListId = UuidGenerator::new();
        $this->commandBus->dispatch(new CreateShoppingListCommand($shoppingListId, $user->getId()));

        return new Response(status: Response::HTTP_CREATED, headers: ['Location' => $this->generateUrl('api_get_shopping_list', ['id' => $shoppingListId])]);
    }
}
