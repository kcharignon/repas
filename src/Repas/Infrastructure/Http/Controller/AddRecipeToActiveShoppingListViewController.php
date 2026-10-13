<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Command\EnsureActiveShoppingList\EnsureActiveShoppingListCommand;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealCommand;
use Repas\Repas\Application\Query\GetActiveShoppingListSummary\GetActiveShoppingListSummaryQuery;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AddRecipeToActiveShoppingListViewController extends AbstractController
{
    public function __construct(
        private readonly RecipeRepository $recipeRepository,
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/shopping-list/active/recipe/{id}/add', name: 'view_shopping_list_add_recipe', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $id): Response
    {
        $connectedUser = $this->getUser();
        assert($connectedUser instanceof AuthenticatedUser);

        // Liste active existante, ou création d'une nouvelle liste (active)
        $this->commandBus->dispatch(new EnsureActiveShoppingListCommand(UuidGenerator::new(), $connectedUser->getId()));

        // Ajout de la recette dans la liste active
        $command = new PlannedMealCommand(
            $connectedUser->getId(),
            $id
        );
        $this->commandBus->dispatch($command);

        // Chargement des donnees pour générer une vue avec des données à jours
        $activeSummary = $this->queryBus->ask(new GetActiveShoppingListSummaryQuery($connectedUser->getId()));
        $recipe = $this->recipeRepository->findOneById($id);
        return new JsonResponse([
            "views" => [
                [
                    "selector" => "#recipe_".$id,
                    "html" => $this->renderView("@Repas/Recipe/_recipe_row.html.twig", [
                        "activeSummary" => $activeSummary,
                        "recipe" => $recipe,
                    ])
                ]
            ]
        ]);
    }

}
