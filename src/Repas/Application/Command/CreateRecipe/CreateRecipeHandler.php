<?php

namespace Repas\Repas\Application\Command\CreateRecipe;

use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Shared\Domain\Event\RecipeCreatedEvent;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class CreateRecipeHandler
{
    public function __construct(
        private RecipeTypeRepository $typeRepository,
        private IngredientRepository $ingredientRepository,
        private UnitRepository $unitRepository,
        private RecipeRepository $recipeRepository,
        private ConversionService $conversionService,
        private EventDispatcherInterface $eventDispatcher,
        private IngredientSlugResolver $slugResolver,
    ) {
    }

    /**
     * @throws RecipeException l'auteur a déjà `Recipe::MAX_PER_AUTHOR` recettes
     * @throws UnitException
     * @throws IngredientException une unité sans chemin de conversion vers l'unité d'achat de son ingrédient
     */
    public function __invoke(CreateRecipeCommand $command): void
    {
        // Le plafond d'abord : un compte qui l'a atteint ne coûte rien de plus (la copie d'une recette n'y passe pas)
        if ($this->recipeRepository->authorHasAtLeast($command->authorId, Recipe::MAX_PER_AUTHOR)) {
            throw RecipeException::limitReached();
        }

        $type = $this->typeRepository->findOneBySlug($command->typeSlug);

        // Un ancien slug (ancien commun dont l'auteur a reçu une copie, ingrédient fusionné) est remplacé par l'ingrédient actuel de
        // l'auteur (ADR 0028 décision 4)
        $resolved = $this->slugResolver->resolve($command->authorId, $command->rows->map(fn(CreateRecipeRowSubCommand $subCmd) => $subCmd->ingredientSlug)->values());
        $slugOf = static fn(CreateRecipeRowSubCommand $subCmd): string => $resolved[$subCmd->ingredientSlug] ?? $subCmd->ingredientSlug;

        // Ingrédients et unités des lignes : une lecture par type, pas une par ligne
        $ingredients = $this->ingredientRepository
            ->findBySlugs($command->rows->map($slugOf))
            ->indexBy(fn(Ingredient $ingredient) => $ingredient->getSlug());
        $units = $this->unitRepository
            ->findBySlugs($command->rows->map(fn(CreateRecipeRowSubCommand $subCmd) => $subCmd->unitSlug)->unique())
            ->indexBy(fn(Unit $unit) => $unit->getSlug());

        $recipe = Recipe::create(
            id: $command->id,
            name: $command->name,
            servings: $command->serving,
            authorId: $command->authorId,
            recipeType: $type,
            rows: $command->rows->map(fn(CreateRecipeRowSubCommand $subCmd) => RecipeRow::create(
                id: UuidGenerator::new(),
                recipeId: $command->id,
                ingredient: $ingredients[$slugOf($subCmd)],
                quantity: $subCmd->quantity,
                unit: $units[$subCmd->unitSlug] ?? throw UnitException::notFound($subCmd->unitSlug),
            )),
            originalId: null,
        );

        foreach ($recipe->getRows() as $row) {
            $this->conversionService->assertConvertibleToPurchaseUnit($row->getIngredient(), $row->getUnit());
        }

        $this->recipeRepository->save($recipe);

        $this->eventDispatcher->dispatch(new RecipeCreatedEvent($command->authorId, $recipe->getId()));
    }
}
