<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Command\CopyRecipe\CopyRecipeCommand;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;

/**
 * La copie d'une recette sur la VRAIE base (ADR 0028 décision 8) : un ingrédient copié emporte TOUJOURS ses conversions propres (« 1 tasse =
 * 125 g » de la farine de l'auteur), avec des identifiants neufs, les mêmes unités et le même coefficient ; l'original reste intact ; l'ingrédient
 * que le copieur avait déjà n'est jamais modifié ni écrasé. La recette copiée se planifie donc : sa ligne en tasses se convertit.
 */
class CopiedIngredientsKeepTheirConversionsTest extends DatabaseTestCase
{
    private DataPersister $persister;
    private string $authorId;
    private string $copierId;
    private Ingredient $authorsFlour;
    private Unit $cup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->persister = new DataPersister(static::getContainer());
        $author = new UserBuilder()->build();
        $copier = new UserBuilder()->withId('copier-id')->withEmail('copier@example.com')->build();
        $this->persister->saveUser($author);
        $this->persister->saveUser($copier);
        $this->authorId = $author->getId();
        $this->copierId = $copier->getId();

        // La farine de l'auteur : cuisinée au gramme, achetée au kilo, avec SA conversion propre « 1 tasse = 125 g »
        $this->cup = new UnitBuilder()->withName('tasse')->build();
        $this->authorsFlour = new IngredientBuilder()->isFlour()->withCreator($author, refreshSlug: true)->build();
        $this->persister->saveConversion(new ConversionBuilder()->withIngredient($this->authorsFlour)->withStartUnit($this->cup)->withEndUnit(new UnitBuilder()->isGramme()->build())->withCoefficient(125)->build());
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $this->persister->saveRecipe(new RecipeBuilder()->withId('recipe-id')->withAuthorId($this->authorId)->addRow(
            new RecipeRowBuilder()->withIngredient($this->authorsFlour)->withUnit(new UnitBuilder()->withName('tasse'))->withQuantity(2),
        )->build());
    }

    public function testACopiedIngredientCarriesItsCustomConversionsWithNewIdentifiers(): void
    {
        // Given : le copieur n'a rien
        $this->entityManager->clear();

        // When
        $this->copy();

        // Then : une copie de la farine, à lui, avec « 1 tasse = 125 g » (identifiant neuf), l'original intact
        $copy = $this->copiedFlour();
        $this->assertNotSame($this->authorsFlour->getSlug(), $copy->getSlug());
        $this->assertSame($this->copierId, $copy->getCreatorId());
        $this->assertSame($this->authorsFlour->getSlug(), $copy->getAdoptedFrom());

        $connection = $this->entityManager->getConnection();
        $copied = $connection->fetchAllAssociative('SELECT id, start_unit, end_unit, coefficient FROM conversion WHERE ingredient = ?', [$copy->getSlug()]);
        $original = $connection->fetchAllAssociative('SELECT id, start_unit, end_unit, coefficient FROM conversion WHERE ingredient = ?', [$this->authorsFlour->getSlug()]);
        $this->assertCount(1, $copied);
        $this->assertCount(1, $original);
        $this->assertNotSame($original[0]['id'], $copied[0]['id']);
        $this->assertSame(['tasse', 'gramme', 125.0], [$copied[0]['start_unit'], $copied[0]['end_unit'], (float) $copied[0]['coefficient']]);
        $this->assertSame(['tasse', 'gramme', 125.0], [$original[0]['start_unit'], $original[0]['end_unit'], (float) $original[0]['coefficient']]);

        // And : la ligne en tasses de la recette copiée se convertit dans l'unité d'achat de la copie, la recette se planifie
        $this->assertEqualsWithDelta(0.25, $this->conversionService()->convertToPurchaseUnit($copy, 2, $this->cup), 1e-12);
    }

    public function testACopiersFlourThatCannotServeTheLineIsLeftAloneAndTheCopyCarriesTheConversion(): void
    {
        // Given : le copieur a SA farine (même nom, même rayon), sans la conversion de la tasse
        $copier = new UserBuilder()->withId($this->copierId)->build();
        $theirs = new IngredientBuilder()->isFlour()->withCreator($copier, refreshSlug: true)->build();
        $this->persister->saveIngredient($theirs);
        $this->entityManager->clear();

        // When
        $this->copy();

        // Then : sa farine n'est ni modifiée ni liée (elle ne sait pas convertir la tasse), la copie de l'auteur porte la conversion
        $connection = $this->entityManager->getConnection();
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM conversion WHERE ingredient = ?', [$theirs->getSlug()]));
        $copy = $this->copiedFlour();
        $this->assertNotSame($theirs->getSlug(), $copy->getSlug());
        $this->assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM conversion WHERE ingredient = ? AND start_unit = ? AND coefficient = ?', [$copy->getSlug(), 'tasse', 125]));
        $this->assertEqualsWithDelta(0.25, $this->conversionService()->convertToPurchaseUnit($copy, 2, $this->cup), 1e-12);
    }

    public function testACopiersFlourThatCanServeTheLineIsKeptWithItsOwnConversionNeverOverwritten(): void
    {
        // Given : le copieur a sa farine, avec sa propre conversion « 1 tasse = 120 g »
        $copier = new UserBuilder()->withId($this->copierId)->build();
        $theirs = new IngredientBuilder()->isFlour()->withCreator($copier, refreshSlug: true)->build();
        $this->persister->saveConversion(new ConversionBuilder()->withIngredient($theirs)->withStartUnit($this->cup)->withEndUnit(new UnitBuilder()->isGramme()->build())->withCoefficient(120)->build());
        $this->entityManager->clear();

        // When
        $this->copy();

        // Then : sa farine est liée, rien n'est créé, sa conversion est intacte (jamais écrasée par celle de l'auteur)
        $recipe = $this->copiedRecipe();
        $this->assertSame($theirs->getSlug(), $recipe->getRows()->reset()->getIngredient()->getSlug());
        $connection = $this->entityManager->getConnection();
        $this->assertSame(2, (int) $connection->fetchOne('SELECT count(*) FROM conversion WHERE ingredient IS NOT NULL'));
        $this->assertSame(120.0, (float) $connection->fetchOne('SELECT coefficient FROM conversion WHERE ingredient = ?', [$theirs->getSlug()]));
    }

    private function copy(): void
    {
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new CopyRecipeCommand('recipe-id', $this->copierId, 'copy-id'));
        $this->entityManager->clear();
    }

    private function copiedRecipe(): Recipe
    {
        return static::getContainer()->get(RecipeRepository::class)->findOneById('copy-id');
    }

    private function copiedFlour(): Ingredient
    {
        /** @var RecipeRow $row */
        $row = $this->copiedRecipe()->getRows()->reset();

        return static::getContainer()->get(IngredientRepository::class)->findOneBySlug($row->getIngredient()->getSlug());
    }

    private function conversionService(): ConversionService
    {
        $service = static::getContainer()->get(ConversionService::class);
        $service->clear();

        return $service;
    }
}
