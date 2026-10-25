<?php

namespace Repas\Repas\Application\Command\PurgeUserData;


use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientAliasRepository;
use Repas\Repas\Domain\Interface\IngredientDistinctRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\SpokenPhraseChoiceRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Supprime les listes de courses, les recettes, les ingrédients personnalisés (copies d'adoption comprises), les alias d'ingrédients, les « Pas le même » et la mémoire de phrase de la commande vocale d'un compte supprimé, comme s'il n'avait
 * jamais existé (ADR 0021). Les suppressions se font en masse, sans passer par `RemoveRecipe` ni `RemoveIngredient` : ces
 * cas d'usage refusent de supprimer ce qu'une liste utilise, et leurs événements mettraient à jour les statistiques d'un
 * compte qui n'existe plus.
 */
#[AsMessageHandler]
readonly class PurgeUserDataHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private RecipeRepository $recipeRepository,
        private ConversionRepository $conversionRepository,
        private IngredientRepository $ingredientRepository,
        private IngredientAliasRepository $aliasRepository,
        private IngredientDistinctRepository $distinctRepository,
        private SpokenPhraseChoiceRepository $spokenPhraseRepository,
    ) {
    }

    public function __invoke(PurgeUserDataCommand $command): void
    {
        $recipeIds = $this->recipeRepository->findIdsByAuthor($command->userId);

        $this->shoppingListRepository->deleteByOwner($command->userId);
        // Une liste d'un autre utilisateur qui planifierait une recette de ce compte (appel direct de l'API) perd ce repas
        $this->shoppingListRepository->removeMealsOfRecipes($recipeIds);

        // Les copies faites par d'autres restent : l'original disparaît, elles deviennent des recettes originales
        $this->recipeRepository->makeCopiesOriginal($recipeIds);
        $this->recipeRepository->deleteByAuthor($command->userId);

        $this->conversionRepository->deleteByIngredientCreator($command->userId);
        $this->ingredientRepository->deleteByCreator($command->userId);
        $this->aliasRepository->deleteByUser($command->userId);
        // Ses « Pas le même » sont une préférence privée : ils partent avec lui
        $this->distinctRepository->deleteByUser($command->userId);
        // Ce qu'il avait appris à Alexa (mémoire de phrase) aussi
        $this->spokenPhraseRepository->deleteByUser($command->userId);
    }
}
