<?php

namespace Repas\Repas\Infrastructure\Http\Api\PlannedMeal;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Infrastructure\Http\EntityVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class PlannedMealApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-lists/active/recipes/{recipeId}', name: 'api_plan_recipe', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Put(summary: 'Planifie une recette dans la liste active de l\'utilisateur et ajoute ses ingrédients (404 si aucune liste n\'est active)')]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Parameter(ref: '#/components/parameters/IfMatch')]
    #[OA\Response(response: 204, description: 'Recette planifiée')]
    #[OA\Response(response: 404, description: 'Ressource inconnue')]
    #[OA\Response(response: 412, description: "La version de la liste n'est plus celle de `If-Match` : elle a été modifiée depuis sa lecture")]
    public function __invoke(string $recipeId, #[CurrentUser] AuthenticatedUser $user, Request $request): Response
    {
        $this->commandBus->dispatch(new PlannedMealCommand($user->getId(), $recipeId, $user->getDefaultServing(), EntityVersion::expected($request)));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
