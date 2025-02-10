<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GetAllRecipeViewController extends AbstractController
{
    public function __construct(
        private readonly RecipeTypeRepository $recipeTypeRepository,
    ) {
    }

    #[Route(path: '/recipes', name: 'view_recipes', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(): Response
    {
        $connectedUser = $this->getUser();
        assert($connectedUser instanceof User);

        $recipeTypes = $this->recipeTypeRepository->findAll();

        return $this->render('@Repas/Recipe/recipe_types.html.twig', [
            'recipeTypes' => $recipeTypes,
        ]);
    }
}
