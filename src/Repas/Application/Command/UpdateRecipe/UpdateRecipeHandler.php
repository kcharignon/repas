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
use Repas\Shared\Domain\Tool\StringTool;
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

        // Le droit de changer le choix « recette secrète » se juge avant toute autre modification : absent, il reste ce qu'il était ; envoyé,
        // il ne change que par l'auteur (ADR 0033), et sinon rien n'est écrit
        if ($command->secret !== null) {
            $recipe->changeSecret($command->secret, $command->userId ?? '');
        }

        $type = $this->recipeTypeRepository->findOneBySlug($command->typeSlug);

        $recipe->update(
            // La première lettre du nom est mise en majuscule en silence, le reste est conservé (ADR 0030)
            name: StringTool::capitalizeFirst($command->name),
            type: $type,
            serving: $command->serving,
        );

        // Les slugs reçus se lisent pour l'AUTEUR de la recette (un administrateur peut la modifier) : un ancien slug est remplacé par
        // l'ingrédient actuel de l'auteur (ADR 0028 décision 4)
        $resolved = $this->slugResolver->resolve($recipe->getAuthorId(), $command->rows->map(fn(UpdateRecipeRowSubCommand $row) => $row->ingredientSlug)->values());
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
