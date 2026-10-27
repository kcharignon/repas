<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Service\IngredientOwnConversions;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\User\Domain\Model\User;

/**
 * Le monde des handlers des conversions propres à un ingrédient : la farine d'un utilisateur (recettes en cuillère à soupe, achat au kilo), la
 * conversion universelle kilo > gramme et la conversion propre « 1 cuillère à soupe = 10 g », qui est celle dont l'ingrédient a besoin.
 */
abstract class IngredientConversionHandlerTestCase extends TestCase
{
    protected IngredientInMemoryRepository $ingredientRepository;
    protected ConversionInMemoryRepository $conversionRepository;
    protected UnitInMemoryRepository $unitRepository;
    protected IngredientAliasInMemoryRepository $aliasRepository;
    protected IngredientSlugResolver $slugResolver;
    protected IngredientOwnConversions $ownConversions;
    protected User $user;
    protected Ingredient $flour;
    protected Conversion $spoonToGramme;
    protected Conversion $kiloToGramme;
    protected Unit $spoon;
    protected Unit $gramme;
    protected Unit $kilo;
    protected Unit $cup;
    protected Unit $glass;

    protected function setUp(): void
    {
        $this->user = new UserBuilder()->build();
        $this->spoon = new UnitBuilder()->isSoupSpoon()->build();
        $this->gramme = new UnitBuilder()->isGramme()->build();
        $this->kilo = new UnitBuilder()->isKilo()->build();
        $this->cup = new UnitBuilder()->withName('tasse')->build();
        $this->glass = new UnitBuilder()->isGlass()->build();
        $this->unitRepository = new UnitInMemoryRepository([$this->spoon, $this->gramme, $this->kilo, $this->cup, $this->glass]);

        $this->flour = new IngredientBuilder()->isFlour()->withCreator($this->user, true)->withDefaultCookingUnit(new UnitBuilder()->isSoupSpoon())->build();
        $this->ingredientRepository = new IngredientInMemoryRepository([$this->flour]);

        $this->spoonToGramme = new ConversionBuilder()->withId('spoon-to-gramme')->withIngredient($this->flour)->withStartUnit($this->spoon)->withEndUnit($this->gramme)->withCoefficient(10)->build();
        $this->kiloToGramme = new ConversionBuilder()->isKiloToGramme()->withId('kilo-to-gramme')->build();
        $this->conversionRepository = new ConversionInMemoryRepository([$this->spoonToGramme, $this->kiloToGramme]);

        $this->aliasRepository = new IngredientAliasInMemoryRepository();
        $this->slugResolver = new IngredientSlugResolver($this->aliasRepository);
        $this->ownConversions = new IngredientOwnConversions(
            $this->conversionRepository,
            $this->ingredientRepository,
            $this->unitRepository,
            new ConversionService($this->conversionRepository, $this->unitRepository),
        );
    }

    /**
     * Les identifiants des conversions propres de la farine, rangés (l'ordre du double n'est pas celui du test).
     *
     * @return list<string>
     */
    protected function ownIds(): array
    {
        $ids = array_map(static fn(Conversion $c): string => $c->getId(), $this->conversionRepository->findByIngredient($this->flour)->toArray());
        sort($ids);

        return $ids;
    }

    /**
     * Les slugs des unités compatibles de la farine telle qu'enregistrée, rangés.
     *
     * @return list<string>
     */
    protected function compatibleUnitSlugs(): array
    {
        $slugs = array_map(static fn(Unit $unit): string => $unit->getSlug(), $this->ingredientRepository->findOneBySlug($this->flour->getSlug())->getCompatibleUnits()->toArray());
        sort($slugs);

        return $slugs;
    }
}
