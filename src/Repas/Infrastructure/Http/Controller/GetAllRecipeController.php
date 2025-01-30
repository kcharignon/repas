<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\GetAllRecipeByAuthor\GetAllRecipeByAuthorQuery;
use Repas\Repas\Application\GetAllRecipeType\GetAllRecipeTypeQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GetAllRecipeController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/recipes', name: 'view_recipes', methods: ['GET'])]
    #[isGranted('ROLE_USER')]
    public function __invoke(): Response
    {
        $connectedUser = $this->getUser();
        assert($connectedUser instanceof User);

        $query = new GetAllRecipeByAuthorQuery($connectedUser->getId());
        $recipes = $this->queryBus->ask($query);

        $query = new GetAllRecipeTypeQuery();
        $recipeTypes = $this->queryBus->ask($query);

        return $this->render('@Repas/Recipe/recipe.html.twig', [
            'recipes' => $recipes,
            'recipeTypes' => $recipeTypes,
        ]);
    }
}
