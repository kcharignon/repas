<?php

namespace Repas\Tests\Repas\Domain\Service;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;

/**
 * Lire d'un coup les conversions de tous les ingrédients d'une recette (LST-005) : une requête pour N produits, jamais pour ceux dont l'unité est déjà
 * l'unité d'achat, et le même résultat qu'une lecture ingrédient par ingrédient.
 */
class ConversionServicePreloadTest extends TestCase
{
    private ConversionInMemoryRepository $repository;
    private ConversionService $service;

    protected function setUp(): void
    {
        $this->repository = new ConversionInMemoryRepository([
            new ConversionBuilder()->isKiloToGramme()->build(),
            new ConversionBuilder()->isBlockToGrammeForButter()->build(),
        ]);
        $this->service = new ConversionService($this->repository, new UnitInMemoryRepository());
    }

    /**
     * @return list<RecipeRow>
     */
    private function rows(): array
    {
        $recipe = new RecipeBuilder()
            ->withServing(4)
            ->addRow(new RecipeRowBuilder()->withIngredient(new IngredientBuilder()->isFlour())->withUnit(new UnitBuilder()->isGramme())->withQuantity(500))
            ->addRow(new RecipeRowBuilder()->withIngredient(new IngredientBuilder()->isButter())->withUnit(new UnitBuilder()->isGramme())->withQuantity(125))
            ->addRow(new RecipeRowBuilder()->withIngredient(new IngredientBuilder()->isEgg())->withUnit(new UnitBuilder()->isUnite())->withQuantity(2))
            ->build();

        return array_values($recipe->getRows()->toArray());
    }

    public function testTheConversionsOfSeveralIngredientsAreReadInOneGo(): void
    {
        // Given / When
        $this->service->preload($this->rows());
        $converted = array_map(fn(RecipeRow $row): float => $this->service->convertRecipeRowToPurchaseUnit($row), $this->rows());

        // Then : farine (gramme -> kilo) et beurre (gramme -> plaquette) en une lecture ; l'œuf n'en a pas besoin ; aucune lecture ingrédient par ingrédient
        $this->assertSame([1, 0], [$this->repository->batchReads, $this->repository->singleReads]);
        $this->assertEqualsWithDelta([0.5, 0.5, 2.0], $converted, 1e-9);
    }

    public function testNothingIsReadWhenNoRowNeedsAConversion(): void
    {
        // Given : un œuf compté en unité, acheté en unité
        $eggOnly = array_values(array_filter($this->rows(), static fn(RecipeRow $row): bool => $row->getIngredient()->getSlug() === 'oeuf'));

        // When
        $this->service->preload($eggOnly);

        // Then
        $this->assertSame([0, 0], [$this->repository->batchReads, $this->repository->singleReads]);
    }

    public function testAnIngredientWhoseGraphIsAlreadyKnownIsNotReadAgain(): void
    {
        // Given
        $this->service->preload($this->rows());

        // When : une deuxième planification dans la même requête
        $this->service->preload($this->rows());

        // Then
        $this->assertSame(1, $this->repository->batchReads);
    }

    public function testPreloadingChangesNoResult(): void
    {
        // Given : le même service, sans lecture groupée
        $alone = new ConversionService($this->repository, new UnitInMemoryRepository());
        $expected = array_map(fn(RecipeRow $row): float => $alone->convertRecipeRowToPurchaseUnit($row), $this->rows());

        // When
        $this->service->preload($this->rows());
        $actual = array_map(fn(RecipeRow $row): float => $this->service->convertRecipeRowToPurchaseUnit($row), $this->rows());

        // Then
        $this->assertEqualsWithDelta($expected, $actual, 1e-9);
    }
}
