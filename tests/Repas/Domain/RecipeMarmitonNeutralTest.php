<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\MarmitonUrl;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;

/**
 * La recette Marmiton neutre dans le modèle (ADR 0037 décisions 3 à 5 et 10) : sans propriétaire, ordinaire, originale, taguée, figée ; ses
 * copies reprennent le lien d'origine mais pas le tag.
 */
class RecipeMarmitonNeutralTest extends TestCase
{
    private const string URL = 'https://www.marmiton.org/recettes/recette_the-tarte-au-citron-meringuee_22082.aspx';

    public function testTheNeutralBelongsToNobodyIsOrdinaryOriginalAndTaggedWithItsLink(): void
    {
        // When
        $neutral = $this->neutral();

        // Then
        $this->assertSame(Recipe::MARMITON_OWNER_ID, $neutral->getAuthorId());
        $this->assertTrue($neutral->isMarmitonNeutral());
        $this->assertFalse($neutral->isSecret());
        $this->assertTrue($neutral->isOriginal());
        $this->assertFalse($neutral->isFavorite());
        $this->assertSame('22082', $neutral->getMarmitonId());
        $this->assertSame(self::URL, $neutral->getSourceUrl());
        $this->assertTrue($neutral->isVisibleTo('any-user'));
    }

    public function testTheReservedOwnerIsNotAnAccountButAFixedWellFormedIdentifier(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-8[0-9a-f]{3}-[0-9a-f]{12}$/', Recipe::MARMITON_OWNER_ID);
    }

    public function testAnOrdinaryRecipeIsNotANeutralAndHasNeitherTagNorLink(): void
    {
        // When
        $recipe = new RecipeBuilder()->withAuthorId('author-id')->build();
        $loaded = Recipe::load(['id' => 'r', 'name' => 'Tarte', 'serving' => 4, 'author_id' => 'author-id', 'type' => new RecipeTypeBuilder()->isMeal()->build(), 'rows' => Tab::newEmptyTyped(RecipeRow::class), 'original_id' => null]);

        // Then
        $this->assertFalse($recipe->isMarmitonNeutral());
        $this->assertNull($recipe->getMarmitonId());
        $this->assertNull($recipe->getSourceUrl());
        $this->assertNull($loaded->getMarmitonId());
        $this->assertNull($loaded->getSourceUrl());
    }

    public function testTheNeutralRefusesEveryModificationWhoeverAsks(): void
    {
        // Given
        $neutral = $this->neutral();
        $type = new RecipeTypeBuilder()->isMeal()->build();
        $modifications = [
            'update' => static fn() => $neutral->update('Autre', $type, 2),
            'setName' => static fn() => $neutral->setName('Autre'),
            'setServing' => static fn() => $neutral->setServing(2),
            'setType' => static fn() => $neutral->setType($type),
            'setRows' => static fn() => $neutral->setRows(Tab::newEmptyTyped(RecipeRow::class)),
            'addRow' => static fn() => $neutral->addRow(new IngredientBuilder()->isFlour()->withCreatorId(Recipe::MARMITON_OWNER_ID)->build(), new UnitBuilder()->isGramme()->build(), 100.0),
            'removal' => static fn() => $neutral->assertNotFrozen(),
        ];

        foreach ($modifications as $name => $modification) {
            // When & Then : le refus est celui de l'autorisation (403), la recette reste telle qu'elle était
            try {
                $modification();
                $this->fail("$name aurait dû être refusé.");
            } catch (RecipeException $exception) {
                $this->assertSame(RecipeException::MARMITON_FROZEN, $exception->getMessage(), $name);
                $this->assertSame(403, $exception->getCode(), $name);
            }
        }
        $this->assertSame('Tarte au citron meringuée', $neutral->getName());
        $this->assertSame(6, $neutral->getServing());
        $this->assertSame(1, $neutral->getRows()->count());
    }

    public function testTheNeutralIsEditableOnceTheCaseSaysTheUserIsAnAdministratorAndOnlyForThatInstance(): void
    {
        // Given
        $neutral = $this->neutral();
        $another = $this->neutral();
        $type = new RecipeTypeBuilder()->isMeal()->build();
        $reservedButter = new IngredientBuilder()->isButter()->withCreatorId(Recipe::MARMITON_OWNER_ID)->build();

        // When
        $neutral->allowAdministratorEdition();
        $neutral->update('Tarte corrigée', $type, 2);
        $neutral->setRows(Tab::newEmptyTyped(RecipeRow::class));
        $neutral->addRow($reservedButter, new UnitBuilder()->isGramme()->build(), 125.0);

        // Then : le contenu change, ce qui définit la recette sans propriétaire (tag, lien, auteur réservé) non
        $this->assertSame(['Tarte corrigée', 2, 1], [$neutral->getName(), $neutral->getServing(), $neutral->getRows()->count()]);
        $this->assertSame(['22082', self::URL, Recipe::MARMITON_OWNER_ID, true], [$neutral->getMarmitonId(), $neutral->getSourceUrl(), $neutral->getAuthorId(), $neutral->isMarmitonNeutral()]);
        // Une autre instance de la même recette, elle, est toujours figée
        $this->expectExceptionObject(RecipeException::frozen());
        $another->update('Autre', $type, 2);
    }

    public function testEvenWhenEditableTheNeutralStillRefusesAnIngredientOfAnAccount(): void
    {
        // Given
        $neutral = $this->neutral()->allowAdministratorEdition();

        // When & Then : la modification d'un administrateur ne met pas un ingrédient de compte dans la neutre
        $this->expectExceptionObject(IngredientException::notFound('beurre'));
        $neutral->addRow(new IngredientBuilder()->isButter()->withCreatorId('admin-id', false)->build(), new UnitBuilder()->isGramme()->build(), 100.0);
    }

    public function testUnlockingChangesNothingForTheSecretAndTheFavoriteChoicesNorForAnOrdinaryRecipe(): void
    {
        // Given
        $neutral = $this->neutral()->allowAdministratorEdition();
        $ordinary = new RecipeBuilder()->withAuthorId('author-id')->build();

        // When
        $ordinary->allowAdministratorEdition();

        // Then : la neutre n'a pas d'auteur dont ce serait le choix ; une recette ordinaire n'a rien de figé à lever
        $this->assertFalse($neutral->changeSecret(false, 'admin-id')->isSecret());
        $this->expectException(RecipeException::class);
        try {
            $neutral->changeFavorite(true, 'admin-id');
        } finally {
            $this->assertFalse($neutral->isFavorite());
            $this->assertFalse($ordinary->isMarmitonNeutral());
        }
    }

    public function testNobodyChangesTheSecretOrTheFavoriteFlagOfTheNeutralNotEvenAnAdministrator(): void
    {
        // Given
        $neutral = $this->neutral();

        // When & Then : renvoyer la valeur actuelle est sans effet, toute autre valeur est refusée, pour tout identifiant
        foreach (['admin-id', 'any-user', 'author-id'] as $userId) {
            $this->assertFalse($neutral->changeSecret(false, $userId)->isSecret());
            $this->assertFalse($neutral->changeFavorite(false, $userId)->isFavorite());
            try {
                $neutral->changeSecret(true, $userId);
                $this->fail('Rendre la neutre secrète aurait dû être refusé.');
            } catch (RecipeException $exception) {
                $this->assertSame(RecipeException::SECRET_FORBIDDEN, $exception->getMessage());
            }
            try {
                $neutral->changeFavorite(true, $userId);
                $this->fail('Mettre la neutre en favoris aurait dû être refusé.');
            } catch (RecipeException $exception) {
                $this->assertSame(RecipeException::FAVORITE_FORBIDDEN, $exception->getMessage());
            }
        }
        $this->assertFalse($neutral->isSecret());
        $this->assertFalse($neutral->isFavorite());
    }

    public function testAnIngredientThatBelongsToAnAccountIsRefusedInTheNeutral(): void
    {
        // Given : un ingrédient d'un compte
        $row = new RecipeRowBuilder()->withRecipeId('neutral-id')->withIngredient(new IngredientBuilder()->isFlour()->build())->withUnit(new UnitBuilder()->isGramme())->withQuantity(250)->build();

        // When & Then : la neutre n'utilise que les ingrédients du propriétaire « personne »
        $this->expectExceptionObject(IngredientException::notFound('farine'));
        Recipe::createMarmitonNeutral('neutral-id', 'Tarte', 4, new RecipeTypeBuilder()->isDessert()->build(), Tab::fromArray($row), MarmitonUrl::parse(self::URL));
    }

    public function testTheNeutralKeepsTheBoundsOfAnyRecipe(): void
    {
        // When & Then
        $this->expectExceptionObject(RecipeException::invalidServing());
        Recipe::createMarmitonNeutral('neutral-id', 'Tarte', 0, new RecipeTypeBuilder()->isDessert()->build(), Tab::newEmptyTyped(RecipeRow::class), MarmitonUrl::parse(self::URL));
    }

    public function testTheCopyKeepsTheLinkButNotTheTagAndIsAnOrdinaryRecipeOfItsAuthor(): void
    {
        // Given
        $neutral = $this->neutral();
        $flour = $neutral->getRows()->reset()->getIngredient();
        $copiers = new IngredientBuilder()->isFlour()->withCreatorId('copier-id')->build();

        // When
        $copy = Recipe::copyFromOriginal('copy-id', $neutral, 'copier-id', [$flour->getSlug() => $copiers], static fn(): string => 'new-row');
        $copyOfTheCopy = Recipe::copyFromOriginal('copy-of-copy', $copy, 'other-id', [$copiers->getSlug() => new IngredientBuilder()->isFlour()->withCreatorId('other-id')->build()], static fn(): string => 'row-2');

        // Then
        $this->assertSame(self::URL, $copy->getSourceUrl());
        $this->assertNull($copy->getMarmitonId(), 'Le tag reste sur la neutre : sans quoi son unicité serait fausse.');
        $this->assertFalse($copy->isMarmitonNeutral());
        $this->assertFalse($copy->isOriginal());
        $this->assertSame($neutral->getId(), $copy->getOriginalId());
        $this->assertSame('copier-id', $copy->getAuthorId());
        $this->assertFalse($copy->isSecret());
        $this->assertFalse($copy->isFavorite());
        $this->assertSame(self::URL, $copyOfTheCopy->getSourceUrl(), 'Le lien d\'origine voyage de copie en copie.');
        $this->assertNull($copyOfTheCopy->getMarmitonId());
        // La copie se modifie, elle : son auteur est un compte
        $copy->update('Ma tarte', new RecipeTypeBuilder()->isMeal()->build(), 2);
        $this->assertSame('Ma tarte', $copy->getName());
    }

    public function testACopyOfAnOrdinaryRecipeHasNoLink(): void
    {
        // Given
        $author = 'author-id';
        $flour = new IngredientBuilder()->isFlour()->withCreatorId($author)->build();
        $original = new RecipeBuilder()->withId('original')->withAuthorId($author)->addRow(new RecipeRowBuilder()->withIngredient($flour))->build();

        // When
        $copy = Recipe::copyFromOriginal('copy', $original, $author, [$flour->getSlug() => $flour], static fn(): string => 'row');

        // Then
        $this->assertNull($copy->getSourceUrl());
    }

    private function neutral(): Recipe
    {
        $flour = new IngredientBuilder()->isFlour()->withCreatorId(Recipe::MARMITON_OWNER_ID)->build();
        $rows = Tab::fromArray(new RecipeRowBuilder()->withRecipeId('neutral-id')->withIngredient($flour)->withUnit(new UnitBuilder()->isGramme())->withQuantity(250)->build());

        return Recipe::createMarmitonNeutral('neutral-id', 'Tarte au citron meringuée', 6, new RecipeTypeBuilder()->isDessert()->build(), $rows, MarmitonUrl::parse(self::URL));
    }
}
