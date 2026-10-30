<?php

namespace Repas\Repas\Application\Command\ChangeRecipeFavorite;

use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Met une recette en favoris ou l'en retire (ADR 0034). Le choix est celui de l'auteur seul.
 */
#[AsMessageHandler]
readonly class ChangeRecipeFavoriteHandler
{

    public function __construct(
        private RecipeRepository $recipeRepository,
    ) {
    }

    /**
     * @throws RecipeException la recette est inconnue ou secrète et d'un autre, la version est périmée, ou l'utilisateur n'est pas l'auteur
     */
    public function __invoke(ChangeRecipeFavoriteCommand $command): void
    {
        $recipe = $this->recipeRepository->findOneById($command->recipeId);
        $recipe->assertVersion($command->expectedVersion);

        // Une recette secrète d'un autre n'existe pas pour cet utilisateur (ADR 0033) : la même réponse qu'un identifiant inconnu
        if (!$recipe->isVisibleTo($command->userId)) {
            throw RecipeException::notFound($command->recipeId);
        }

        $recipe->changeFavorite($command->favorite, $command->userId);
        $this->recipeRepository->save($recipe);
    }
}
