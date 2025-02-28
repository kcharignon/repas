<?php

namespace Repas\Repas\Application\CopyRecipe;

use Repas\Repas\Domain\Event\RecipesOrIngredientsCreatedEvent;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class CopyRecipeHandler
{

    public function __construct(
        private RecipeRepository $recipeRepository,
        private UserRepository $userRepository,
        private IngredientRepository $ingredientRepository,
        private ConversionRepository $conversionRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(CopyRecipeCommand $command): void
    {
        $originalRecipe = $this->recipeRepository->findOneById($command->recipeId);
        $author = $this->userRepository->findOneById($command->authorId);

        // On copie uniquement les ingredients personnalisés de la recette originelle
        /** @var RecipeRow $ingredient */
        $newIngredientSlugs = Tab::newEmptyTyped('string') ;
        foreach ($originalRecipe->getRows() as $recipeRow) {
            $ingredient = $recipeRow->getIngredient();
            // Si c'est un ingredient personnalisé, on le copie
            if ($ingredient->getCreator()) {
                $newIngredient = Ingredient::copyFromOriginal($ingredient, $author);
                $newIngredientSlugs[] = $newIngredient->getSlug();
                $this->ingredientRepository->save($newIngredient);
                // Si des conversions existent, on les copies
                $conversions = $this->conversionRepository->findByIngredient($recipeRow->getIngredient());
                foreach ($conversions as $conversion) {
                    $newConversion = Conversion::copyFromOriginal(UuidGenerator::new(), $conversion, $newIngredient);
                    $this->conversionRepository->save($newConversion);
                }
            }
        }

        $recipe = Recipe::copyFromOriginal(
            UuidGenerator::new(),
            $originalRecipe,
            $author,
        );
        $this->recipeRepository->save($recipe);

        $this->eventDispatcher->dispatch(new RecipesOrIngredientsCreatedEvent(
            userId: $author->getId(),
            ingredientSlugs: $newIngredientSlugs,
            recipeIds: Tab::fromArray($recipe->getId())
        ));
    }
}
