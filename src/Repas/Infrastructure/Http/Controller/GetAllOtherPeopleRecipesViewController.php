<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Query\ListOtherPeopleRecipes\ListOtherPeopleRecipesQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GetAllOtherPeopleRecipesViewController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/recipes/other-people', name: 'view_other_people_recipes', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(): Response
    {
        $currentUser = $this->getUser();
        assert($currentUser instanceof AuthenticatedUser);

        $recipes = $this->queryBus->ask(new ListOtherPeopleRecipesQuery($currentUser->getId()));

        return $this->render('@Repas/Recipe/other_people_recipe.html.twig', [
            'recipes' => $recipes,
        ]);
    }

}
