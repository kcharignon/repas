<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Domain\Interface\RecipeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GetOneRecipeController extends AbstractController
{
    public function __construct(
        private readonly RecipeRepository $recipeRepository,
    ) {
    }

    #[Route(path: '/recipe/{id}', name: 'view_one_recipe', methods: ['GET'])]
    #[isGranted('ROLE_USER')]
    public function __invoke(string $id): Response
    {
        $recipe = $this->recipeRepository->findOneById($id);
        return $this->render('@Repas/Recipe/one_recipe.html.twig', [
            'recipe' => $recipe,
        ]);
    }

}
