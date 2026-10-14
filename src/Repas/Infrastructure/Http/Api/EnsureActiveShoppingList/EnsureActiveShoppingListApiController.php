<?php

namespace Repas\Repas\Infrastructure\Http\Api\EnsureActiveShoppingList;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\EnsureActiveShoppingList\EnsureActiveShoppingListCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class EnsureActiveShoppingListApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-lists/active', name: 'api_ensure_active_shopping_list', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Put(summary: 'S\'assure que l\'utilisateur a une liste active : en crée une si besoin (sans effet sinon)')]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Response(response: 204, description: 'Liste active assurée')]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user): Response
    {
        $this->commandBus->dispatch(new EnsureActiveShoppingListCommand(UuidGenerator::new(), $user->getId()));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
