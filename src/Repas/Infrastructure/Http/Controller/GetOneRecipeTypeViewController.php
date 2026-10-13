<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Query\GetActiveShoppingListSummary\GetActiveShoppingListSummaryQuery;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Infrastructure\Loader\RecipeLoader;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GetOneRecipeTypeViewController extends AbstractController
{
    public function __construct(
        private readonly RecipeLoader           $recipeLoader,
        private readonly RecipeTypeRepository   $recipeTypeRepository,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/recipe/type/{slug}', name: 'view_one_recipe_type')]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $slug): Response
    {
        $userConnected = $this->getUser();
        assert($userConnected instanceof User);
        $type = $this->recipeTypeRepository->findOneBySlug($slug);
        $recipes = $this->recipeLoader->findByAuthorAndType($userConnected, $type);
        $activeSummary = $this->queryBus->ask(new GetActiveShoppingListSummaryQuery($userConnected->getId()));
        return $this->render('@Repas/Recipe/type.html.twig', [
            'recipeType' => $type,
            'recipes' => $recipes,
            'activeSummary' => $activeSummary,
        ]);
    }
}
