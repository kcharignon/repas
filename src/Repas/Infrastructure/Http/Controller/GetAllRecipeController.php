<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GetAllRecipeController extends AbstractController
{
    public function __construct(
        private readonly RecipeRepository $recipeRepository,
        private readonly RecipeTypeRepository $recipeTypeRepository,
    ) {
    }

    #[Route(path: '/recipes', name: 'view_recipes', methods: ['GET'])]
    #[isGranted('ROLE_USER')]
    public function __invoke(): Response
    {
        $connectedUser = $this->getUser();
        assert($connectedUser instanceof User);

        $recipes = $this->recipeRepository->findByAuthor($connectedUser);

        $recipeTypes = $this->recipeTypeRepository->findAll();

        return $this->render('@Repas/Recipe/recipe.html.twig', [
            'recipes' => $recipes,
            'recipeTypes' => $recipeTypes,
        ]);
    }
}
