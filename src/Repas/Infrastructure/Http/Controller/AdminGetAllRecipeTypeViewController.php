<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Query\ListRecipeTypes\ListRecipeTypesQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AdminGetAllRecipeTypeViewController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/admin/recipe-type', name: 'view_admin_recipe_types', methods: ['GET'])]
    #[IsGranted("ROLE_ADMIN")]
    public function __invoke(): Response
    {
        return $this->render('@Repas/RecipeType/admin_recipe_types.html.twig', [
            'recipeTypes' => $this->queryBus->ask(new ListRecipeTypesQuery()),
        ]);
    }

}
