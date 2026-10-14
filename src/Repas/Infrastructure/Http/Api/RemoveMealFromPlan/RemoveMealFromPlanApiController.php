<?php

namespace Repas\Repas\Infrastructure\Http\Api\RemoveMealFromPlan;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class RemoveMealFromPlanApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-lists/active/recipes/{recipeId}', name: 'api_unplan_recipe', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Delete(summary: 'Retire une recette de la liste active de l\'utilisateur et déduit ses ingrédients')]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Response(response: 204, description: 'Recette retirée')]
    #[OA\Response(response: 404, description: 'Ressource inconnue')]
    public function __invoke(string $recipeId, #[CurrentUser] AuthenticatedUser $user): Response
    {
        $this->commandBus->dispatch(new removeMealFromPlanCommand($user->getId(), $recipeId));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
