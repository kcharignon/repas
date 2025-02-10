<?php

namespace Repas\Repas\Application\UpdateRecipe;

use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Shared\Domain\Tool\Tab;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class UpdateRecipeHandler
{
    public function __construct(
        private RecipeRepository $recipeRepository,
        private RecipeTypeRepository $recipeTypeRepository,
        private IngredientRepository $ingredientRepository,
        private UnitRepository $unitRepository,
    ) {
    }

    /**
     * @throws UnitException
     * @throws RecipeException
     * @throws IngredientException
     */
    public function __invoke(UpdateRecipeCommand $command): void
    {

        $recipe = $this->recipeRepository->findOneById($command->id);

        $type = $this->recipeTypeRepository->findOneBySlug($command->typeSlug);

        $recipe->update(
            name: $command->name,
            type: $type,
            serving: $command->serving,
        );

        $recipe->setRows(Tab::newEmptyTyped(RecipeRow::class));
        foreach ($command->rows as $row) {
            $ingredient = $this->ingredientRepository->findOneBySlug($row->ingredientSlug);
            $unit = $this->unitRepository->findOneBySlug($row->unitSlug);
            $quantity = $row->quantity;
            $recipe->addRow($ingredient, $unit, $quantity);
        }

        $this->recipeRepository->save($recipe);
    }
}
