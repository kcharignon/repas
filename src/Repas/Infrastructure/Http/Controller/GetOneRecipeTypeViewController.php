<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GetOneRecipeTypeViewController extends AbstractController
{
    public function __construct(
        private readonly RecipeRepository $recipeRepository,
        private readonly RecipeTypeRepository $recipeTypeRepository,
        private readonly ShoppingListRepository $shoppingListRepository,
    ) {
    }

    #[Route(path: '/recipe/type/{slug}', name: 'view_one_recipe_type')]
    public function __invoke(string $slug): Response
    {
        $userConnected = $this->getUser();
        assert($userConnected instanceof User);
        $type = $this->recipeTypeRepository->findOneBySlug($slug);
        $recipes = $this->recipeRepository->findByAuthorAndType($userConnected, $type);
        $shoppingList = $this->shoppingListRepository->findOnePlanningByOwner($userConnected);
        return $this->render('@Repas/Recipe/type.html.twig', [
            'recipeType' => $type,
            'recipes' => $recipes,
            'shoppingList' => $shoppingList,
        ]);
    }
}
