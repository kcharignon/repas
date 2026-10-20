<?php

namespace Repas\Repas\Application\Command\RemoveIngredient;

use Repas\Shared\Domain\Event\IngredientRemovedEvent;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientAliasRepository;
use Repas\Repas\Domain\Interface\IngredientDistinctRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class RemoveIngredientHandler
{

    public function __construct(
        private IngredientRepository $ingredientRepository,
        private RecipeRepository $recipeRepository,
        private ShoppingListRepository $shoppingListRepository,
        private ConversionRepository $conversionRepository,
        private IngredientAliasRepository $aliasRepository,
        private IngredientDistinctRepository $distinctRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @throws IngredientException
     */
    public function __invoke(RemoveIngredientCommand $command): void
    {
        $ingredient = $this->ingredientRepository->findOneBySlug($command->ingredientId);

        // Si l'ingrédient est dans une recette, alors on ne le supprime pas
        if ($this->recipeRepository->isIngredientUsed($ingredient->getSlug())) {
            throw IngredientException::cannotBeRemoveExistInRecipe($ingredient->getId());
        }

        // Si l'ingrédient est dans une liste de course, alors on ne le supprime pas
        if ($this->shoppingListRepository->isIngredientUsed($ingredient->getSlug())) {
            throw IngredientException::cannotBeRemoveExistInShoppingList($ingredient->getId());
        }

        $this->conversionRepository->deleteByIngredient($ingredient);
        $this->ingredientRepository->delete($ingredient);
        if ($ingredient->getCreatorId() !== null) {
            // Les alias qui désignaient cet ingrédient disparaissent avec lui : le commun adopté redevient visible pour son propriétaire (ADR 0028)
            $this->aliasRepository->deleteByIngredientSlug($ingredient->getCreatorId(), $ingredient->getSlug());
            // Idem pour ses « Pas le même » : la paire parlait de cet ingrédient
            $this->distinctRepository->deleteByIngredientSlug($ingredient->getCreatorId(), $ingredient->getSlug());
            // Une adoption n'est pas une création : la supprimer ne décrémente pas la statistique « ingrédients créés » (ADR 0028 décision 7)
            if (!$ingredient->isAdoption()) {
                $this->eventDispatcher->dispatch(new IngredientRemovedEvent($ingredient->getCreatorId(), $ingredient->getId()));
            }
        }
    }
}
