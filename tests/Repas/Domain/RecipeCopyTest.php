<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;

/**
 * La copie d'une recette ne crée ni ne cherche aucun ingrédient (ADR 0028 décision 8) : le cas d'usage lui donne ceux que le copieur
 * utilisera, et la recette refuse celui d'un autre.
 */
class RecipeCopyTest extends TestCase
{
    public function testTheCopyKeepsTheRowsOfTheOriginalOnTheIngredientsTheCopierUses(): void
    {
        // Given : l'auteur a son beurre, le copieur le sien
        $author = new UserBuilder()->withId('author-id')->build();
        $copier = new UserBuilder()->withId('copier-id')->build();
        $authors = new IngredientBuilder()->isButter()->withCreator($author, true)->build();
        $copiers = new IngredientBuilder()->isButter()->withCreator($copier, true)->build();
        $original = new RecipeBuilder()->withId('original')->withAuthor($author)->addRow(new RecipeRowBuilder()->withIngredient($authors)->withUnit(new UnitBuilder()->isGramme())->withQuantity(125))->build();

        // When
        $copy = Recipe::copyFromOriginal('copy', $original, 'copier-id', [$authors->getSlug() => $copiers], static fn(): string => 'new-row');

        // Then
        $row = $copy->getRows()->reset();
        $this->assertSame($copiers->getSlug(), $row->getIngredient()->getSlug());
        $this->assertSame(125.0, (float) $row->getQuantity());
        $this->assertSame('gramme', $row->getUnit()->getSlug());
        $this->assertSame('new-row', $row->getId());
        $this->assertSame('copy', $row->getRecipeId());
        $this->assertSame('original', $copy->getOriginalId());
        $this->assertSame('copier-id', $copy->getAuthorId());
        $this->assertSame($authors->getSlug(), $original->getRows()->reset()->getIngredient()->getSlug(), 'L\'original n\'est pas touché.');
    }

    public function testAnIngredientOfSomeoneElseIsRefusedAsUnknown(): void
    {
        // Given
        $author = new UserBuilder()->withId('author-id')->build();
        $authors = new IngredientBuilder()->isButter()->withCreator($author, true)->build();
        $original = new RecipeBuilder()->withId('original')->withAuthor($author)->addRow(new RecipeRowBuilder()->withIngredient($authors))->build();

        // Then : garder l'ingrédient de l'auteur dans la copie du copieur fuiterait l'ingrédient d'un autre
        $this->expectExceptionObject(IngredientException::notFound($authors->getSlug()));

        // When
        Recipe::copyFromOriginal('copy', $original, 'copier-id', [$authors->getSlug() => $authors], static fn(): string => 'new-row');
    }

    public function testACommonIngredientIsAvailableToAnyone(): void
    {
        // Given
        $author = new UserBuilder()->withId('author-id')->build();
        $common = new IngredientBuilder()->isButter()->build();
        $original = new RecipeBuilder()->withId('original')->withAuthor($author)->addRow(new RecipeRowBuilder()->withIngredient($common))->build();

        // When
        $copy = Recipe::copyFromOriginal('copy', $original, 'copier-id', [$common->getSlug() => $common], static fn(): string => 'new-row');

        // Then
        $this->assertSame('beurre', $copy->getRows()->reset()->getIngredient()->getSlug());
    }

    public function testAnIngredientTheCaseUseDidNotRetainIsRefused(): void
    {
        // Given
        $author = new UserBuilder()->withId('author-id')->build();
        $common = new IngredientBuilder()->isButter()->build();
        $original = new RecipeBuilder()->withId('original')->withAuthor($author)->addRow(new RecipeRowBuilder()->withIngredient($common))->build();

        // Then
        $this->expectExceptionObject(IngredientException::notFound('beurre'));

        // When
        Recipe::copyFromOriginal('copy', $original, 'copier-id', [], static fn(): string => 'new-row');
    }
}
