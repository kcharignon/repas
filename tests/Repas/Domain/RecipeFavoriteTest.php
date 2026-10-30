<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;

/**
 * La recette favorite (ADR 0034) dans le modèle : un drapeau de l'auteur, modifiable par lui seul, que la copie n'hérite jamais.
 */
class RecipeFavoriteTest extends TestCase
{
    public function testARecipeIsNotFavoriteByDefaultWhateverTheWayItIsBuilt(): void
    {
        // Given & Then : la création, le chargement d'une ligne sans la colonne et le builder donnent une recette sans étoile
        $created = Recipe::create('recipe-id', 'Tarte', 4, 'author-id', new RecipeTypeBuilder()->isMeal()->build(), Tab::newEmptyTyped(RecipeRow::class), null);
        $loaded = Recipe::load(['id' => 'recipe-id', 'name' => 'Tarte', 'serving' => 4, 'author_id' => 'author-id', 'type' => new RecipeTypeBuilder()->isMeal()->build(), 'rows' => Tab::newEmptyTyped(RecipeRow::class), 'original_id' => null]);

        $this->assertFalse($created->isFavorite());
        $this->assertFalse($loaded->isFavorite());
        $this->assertFalse(new RecipeBuilder()->build()->isFavorite());
        $this->assertTrue(new RecipeBuilder()->asFavorite()->build()->isFavorite());
    }

    public function testTheAuthorPutsItInFavoritesAndTakesItOutAgain(): void
    {
        // Given
        $recipe = new RecipeBuilder()->withAuthorId('author-id')->build();

        // When & Then
        $this->assertTrue($recipe->changeFavorite(true, 'author-id')->isFavorite());
        $this->assertFalse($recipe->changeFavorite(false, 'author-id')->isFavorite());
    }

    public function testAnotherUserCannotChangeTheFlagButSendingTheCurrentValueIsHarmless(): void
    {
        // Given
        $ordinary = new RecipeBuilder()->withAuthorId('author-id')->build();
        $favorite = new RecipeBuilder()->withAuthorId('author-id')->asFavorite()->build();

        // When & Then : l'autre valeur est refusée, la valeur actuelle est sans effet
        $this->assertFalse($ordinary->changeFavorite(false, 'admin-id')->isFavorite());
        $this->assertTrue($favorite->changeFavorite(true, 'admin-id')->isFavorite());
        $this->expectExceptionObject(RecipeException::favoriteForbidden());
        $ordinary->changeFavorite(true, 'admin-id');
    }

    public function testARefusedChangeLeavesTheFlagAsItWas(): void
    {
        // Given
        $favorite = new RecipeBuilder()->withAuthorId('author-id')->asFavorite()->build();

        // When
        try {
            $favorite->changeFavorite(false, 'admin-id');
            $this->fail('Le refus attendu n\'a pas eu lieu.');
        } catch (RecipeException $exception) {
            // Then
            $this->assertSame(403, $exception->getCode());
            $this->assertTrue($favorite->isFavorite());
        }
    }
}
