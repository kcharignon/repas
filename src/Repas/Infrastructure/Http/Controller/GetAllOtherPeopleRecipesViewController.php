<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GetAllOtherPeopleRecipesViewController extends AbstractController
{
    public function __construct(
        private readonly RecipeRepository $recipeRepository,
    ) {
    }

    #[Route(path: '/recipes/other-people', name: 'view_other_people_recipes', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(): Response
    {
        $currentUser = $this->getUser();
        assert($currentUser instanceof User);

        $recipes = $this->recipeRepository->findByNotAuthorAndNotCopy($currentUser);

        return $this->render('@Repas/Recipe/other_people_recipe.html.twig', [
            'recipes' => $recipes,
        ]);
    }

}
