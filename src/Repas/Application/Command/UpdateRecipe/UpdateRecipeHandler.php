<?php

namespace Repas\Repas\Application\Command\UpdateRecipe;

use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Domain\Service\ConversionService;
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
        private ConversionService $conversionService,
        private IngredientSlugResolver $slugResolver,
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
        $recipe->assertVersion($command->expectedVersion);

        $type = $this->recipeTypeRepository->findOneBySlug($command->typeSlug);

        $recipe->update(
            name: $command->name,
            type: $type,
            serving: $command->serving,
        );

        // Les slugs reçus se lisent pour l'AUTEUR de la recette (un administrateur peut la modifier) : un commun qu'il a adopté, ou adopte
        // à la première utilisation, est remplacé par sa copie (ADR 0028 décision 4)
        $resolved = $this->slugResolver->resolveForUser($recipe->getAuthorId(), $command->rows->map(fn(UpdateRecipeRowSubCommand $row) => $row->ingredientSlug)->values());
        $slugOf = static fn(UpdateRecipeRowSubCommand $row): string => $resolved[$row->ingredientSlug] ?? $row->ingredientSlug;

        // Ingrédients et unités des lignes : une lecture par type, pas une par ligne
        $ingredients = $this->ingredientRepository
            ->findBySlugs($command->rows->map($slugOf))
            ->indexBy(fn(Ingredient $ingredient) => $ingredient->getSlug());
        $units = $this->unitRepository
            ->findBySlugs($command->rows->map(fn(UpdateRecipeRowSubCommand $row) => $row->unitSlug)->unique())
            ->indexBy(fn(Unit $unit) => $unit->getSlug());

        $recipe->setRows(Tab::newEmptyTyped(RecipeRow::class));
        foreach ($command->rows as $row) {
            $ingredient = $ingredients[$slugOf($row)];
            $unit = $units[$row->unitSlug] ?? throw UnitException::notFound($row->unitSlug);
            $this->conversionService->assertConvertibleToPurchaseUnit($ingredient, $unit);
            $recipe->addRow($ingredient, $unit, $row->quantity);
        }

        $this->recipeRepository->save($recipe);
    }
}
