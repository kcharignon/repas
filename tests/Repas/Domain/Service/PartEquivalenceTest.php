<?php

namespace Repas\Tests\Repas\Domain\Service;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Repas\Domain\Service\PartEquivalence;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;

/**
 * Ce que vaut une quantité d'une partie en unités d'achat de son parent (ADR 0038 décisions 3 et 4) : la quantité de la partie, ramenée en unités de
 * recette du parent par le ratio, puis convertie par le graphe du parent. Aucun arrondi.
 */
class PartEquivalenceTest extends TestCase
{
    private ConversionInMemoryRepository $repository;
    private PartEquivalence $equivalence;

    protected function setUp(): void
    {
        $this->repository = new ConversionInMemoryRepository([
            new ConversionBuilder()->isKiloToGramme()->build(),
            new ConversionBuilder()->isBlockToGrammeForButter()->build(),
        ]);
        $this->equivalence = new PartEquivalence(new ConversionService($this->repository, new UnitInMemoryRepository()));
    }

    public function testAYolkIsWorthOneEggAtRatioOne(): void
    {
        // Given : « Œuf » se cuisine et s'achète à l'unité ; un jaune fait un œuf
        $egg = new IngredientBuilder()->isEgg()->build();
        $yolk = new IngredientBuilder()->withName("Jaune d'œuf")->withParent($egg->getSlug(), 1.0)->build();

        // When / Then : 2 jaunes valent 2 œufs, sans lire aucune conversion
        $this->assertSame(2.0, $this->equivalence->of($yolk, $egg, 2.0));
        $this->assertSame([0, 0], [$this->repository->batchReads, $this->repository->singleReads]);
    }

    public function testTheRatioCountsHowManyUnitsOfThePartMakeOneUnitOfTheParent(): void
    {
        // Given : une gousse de vanille se compose de deux demi-gousses (ratio 2)
        $pod = new IngredientBuilder()->withName('Gousse de vanille')->build();
        $half = new IngredientBuilder()->withName('Demi-gousse de vanille')->withParent($pod->getSlug(), 2.0)->build();

        // When / Then : 3 demi-gousses valent 1,5 gousse, jamais arrondi
        $this->assertSame(1.5, $this->equivalence->of($half, $pod, 3.0));
        $this->assertSame(0.5, $this->equivalence->of($half, $pod, 1.0));
    }

    public function testAFractionalRatioMakesThePartWorthMore(): void
    {
        // Given : 1 unité du parent donne 0,5 unité de la partie (la partie est « grosse »)
        $parent = new IngredientBuilder()->isEgg()->build();
        $part = new IngredientBuilder()->withName('Gros morceau')->withParent($parent->getSlug(), 0.5)->build();

        // When / Then
        $this->assertSame(4.0, $this->equivalence->of($part, $parent, 2.0));
    }

    public function testTheParentConvertsItsRecipeUnitIntoItsPurchaseUnit(): void
    {
        // Given : le beurre se cuisine au gramme et s'achète en plaquettes de 250 g ; sa partie « Beurre clarifié » : 0,5 unité par gramme de beurre
        $butter = new IngredientBuilder()->isButter()->build();
        $clarified = new IngredientBuilder()->withName('Beurre clarifié')->withParent($butter->getSlug(), 0.5)->build();

        // When : 125 unités de la partie valent 250 g de beurre, soit une plaquette
        $equivalent = $this->equivalence->of($clarified, $butter, 125.0);

        // Then
        $this->assertEqualsWithDelta(1.0, $equivalent, 1e-12);
    }

    public function testAParentWhoseRecipeUnitCannotBeConvertedRefuses(): void
    {
        // Given : un parent cuisiné au gramme, acheté en plaquettes, sans la conversion de l'ingrédient (le graphe est vide)
        $this->equivalence = new PartEquivalence(new ConversionService(new ConversionInMemoryRepository([]), new UnitInMemoryRepository()));
        $butter = new IngredientBuilder()->isButter()->build();
        $part = new IngredientBuilder()->withName('Beurre clarifié')->withParent($butter->getSlug(), 1.0)->build();

        // When / Then
        $this->expectException(IngredientException::class);
        $this->equivalence->of($part, $butter, 10.0);
    }

    public function testAnIngredientWithoutRatioIsNotAPart(): void
    {
        // Given
        $egg = new IngredientBuilder()->isEgg()->build();
        $notAPart = new IngredientBuilder()->withName('Autre')->build();

        // When / Then
        $this->expectException(IngredientException::class);
        $this->expectExceptionMessage(IngredientException::PART_RATIO_INVALID);
        $this->equivalence->of($notAPart, $egg, 1.0);
    }
}
