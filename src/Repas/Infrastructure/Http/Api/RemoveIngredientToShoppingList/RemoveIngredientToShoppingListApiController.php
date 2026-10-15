<?php

namespace Repas\Repas\Infrastructure\Http\Api\RemoveIngredientToShoppingList;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\RemoveIngredientToShoppingList\RemoveIngredientToShoppingListCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Infrastructure\Http\EntityVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class RemoveIngredientToShoppingListApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-lists/active/ingredients/{slug}/decrease', name: 'api_decrease_ingredient', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(summary: 'Retire 1 à la quantité d\'un ingrédient dans la liste active de l\'utilisateur (la ligne disparaît à zéro)')]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Parameter(ref: '#/components/parameters/IfMatch')]
    #[OA\Response(response: 204, description: 'Quantité diminuée')]
    #[OA\Response(response: 404, description: 'Ressource inconnue')]
    #[OA\Response(response: 412, description: "La version de la liste n'est plus celle de `If-Match` : elle a été modifiée depuis sa lecture")]
    public function __invoke(string $slug, #[CurrentUser] AuthenticatedUser $user, Request $request): Response
    {
        $this->commandBus->dispatch(new RemoveIngredientToShoppingListCommand($user->getId(), $slug, EntityVersion::expected($request)));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
