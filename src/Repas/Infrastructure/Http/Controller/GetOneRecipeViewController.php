<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Query\GetRecipe\GetRecipeQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GetOneRecipeViewController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/recipe/{id}', name: 'view_recipe', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $id): Response
    {
        $recipe = $this->queryBus->ask(new GetRecipeQuery($id));
        return $this->render('@Repas/Recipe/one_recipe.html.twig', [
            'recipe' => $recipe,
        ]);
    }

}
