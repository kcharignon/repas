<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Query\GetActiveShoppingListSummary\GetActiveShoppingListSummaryQuery;
use Repas\Repas\Application\Query\ListRecipesByType\ListRecipesByTypeQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GetOneRecipeTypeViewController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/recipe/type/{slug}', name: 'view_one_recipe_type')]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $slug): Response
    {
        $userConnected = $this->getUser();
        assert($userConnected instanceof AuthenticatedUser);
        $recipesOfType = $this->queryBus->ask(new ListRecipesByTypeQuery($userConnected->getId(), $slug));
        $activeSummary = $this->queryBus->ask(new GetActiveShoppingListSummaryQuery($userConnected->getId()));
        return $this->render('@Repas/Recipe/type.html.twig', [
            'recipeType' => $recipesOfType->recipeType,
            'recipes' => $recipesOfType->recipes,
            'activeSummary' => $activeSummary,
        ]);
    }
}
