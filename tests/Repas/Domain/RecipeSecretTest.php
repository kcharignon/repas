<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;

/**
 * La recette secrète (ADR 0033) dans le modèle : visible de son auteur seul, drapeau modifiable par lui seul, jamais recopié par une copie.
 */
class RecipeSecretTest extends TestCase
{
    public function testARecipeIsOrdinaryByDefaultWhateverTheWayItIsBuilt(): void
    {
        // Given & Then : la création, le chargement d'une ligne sans la colonne et le builder donnent une recette visible de tous
        $created = Recipe::create('recipe-id', 'Tarte', 4, 'author-id', new RecipeTypeBuilder()->isMeal()->build(), Tab::newEmptyTyped(RecipeRow::class), null);
        $loaded = Recipe::load(['id' => 'recipe-id', 'name' => 'Tarte', 'serving' => 4, 'author_id' => 'author-id', 'type' => new RecipeTypeBuilder()->isMeal()->build(), 'rows' => Tab::newEmptyTyped(RecipeRow::class), 'original_id' => null]);

        $this->assertFalse($created->isSecret());
        $this->assertFalse($loaded->isSecret());
        $this->assertFalse(new RecipeBuilder()->build()->isSecret());
    }

    public function testARecipeCanBeCreatedSecret(): void
    {
        // When
        $recipe = Recipe::create('recipe-id', 'Tarte', 4, 'author-id', new RecipeTypeBuilder()->isMeal()->build(), Tab::newEmptyTyped(RecipeRow::class), null, secret: true);

        // Then
        $this->assertTrue($recipe->isSecret());
    }

    public function testAnOrdinaryRecipeIsVisibleToEveryoneAndASecretOneToItsAuthorOnly(): void
    {
        // Given
        $ordinary = new RecipeBuilder()->withAuthorId('author-id')->build();
        $secret = new RecipeBuilder()->withAuthorId('author-id')->asSecret()->build();

        // Then : aucun passe-droit, un administrateur n'est qu'un utilisateur de plus
        $this->assertTrue($ordinary->isVisibleTo('author-id'));
        $this->assertTrue($ordinary->isVisibleTo('someone-else'));
        $this->assertTrue($secret->isVisibleTo('author-id'));
        $this->assertFalse($secret->isVisibleTo('someone-else'));
        $this->assertFalse($secret->isVisibleTo('admin-id'));
    }

    public function testTheAuthorSwitchesTheFlagBothWays(): void
    {
        // Given
        $recipe = new RecipeBuilder()->withAuthorId('author-id')->build();

        // When & Then
        $this->assertTrue($recipe->changeSecret(true, 'author-id')->isSecret());
        $this->assertFalse($recipe->changeSecret(false, 'author-id')->isSecret());
    }

    /**
     * @return iterable<string, array{bool, bool}> le choix actuel, le choix demandé (différent)
     */
    public static function differentChoices(): iterable
    {
        yield 'rendre secrète' => [false, true];
        yield 'rendre visible' => [true, false];
    }

    #[DataProvider('differentChoices')]
    public function testSomeoneElseCannotChangeTheFlag(bool $current, bool $requested): void
    {
        // Given
        $recipe = new RecipeBuilder()->withAuthorId('author-id')->asSecret($current)->build();

        // Then
        $this->expectExceptionObject(RecipeException::secretForbidden());

        // When
        $recipe->changeSecret($requested, 'admin-id');
    }

    public function testSomeoneElseSendingTheCurrentChoiceChangesNothingAndIsNotRefused(): void
    {
        // Given
        $secret = new RecipeBuilder()->withAuthorId('author-id')->asSecret()->build();
        $ordinary = new RecipeBuilder()->withAuthorId('author-id')->build();

        // When & Then
        $this->assertTrue($secret->changeSecret(true, 'admin-id')->isSecret());
        $this->assertFalse($ordinary->changeSecret(false, 'admin-id')->isSecret());
    }

    public function testTheForbiddenChangeIsAnAuthorizationRefusal(): void
    {
        $this->assertSame([403, RecipeException::SECRET_FORBIDDEN], [RecipeException::secretForbidden()->getCode(), RecipeException::secretForbidden()->getMessage()]);
    }

    public function testTheCopyOfASecretRecipeBySomeoneElseIsNotFound(): void
    {
        // Given
        $original = new RecipeBuilder()->withId('original')->withAuthorId('author-id')->asSecret()->build();

        // Then : elle n'existe pas pour lui, la garde finale du modèle même si le cas d'usage l'oubliait
        $this->expectExceptionObject(RecipeException::notFound('original'));

        // When
        Recipe::copyFromOriginal('copy', $original, 'copier-id', [], static fn(): string => 'new-row');
    }

    public function testTheCopyIsAlwaysOrdinaryWhoeverCopies(): void
    {
        // Given : l'auteur copie sa propre recette secrète, un autre copie une recette ordinaire
        $secret = new RecipeBuilder()->withId('secret')->withAuthorId('author-id')->asSecret()->build();
        $ordinary = new RecipeBuilder()->withId('ordinary')->withAuthorId('author-id')->build();

        // When
        $copyOfSecret = Recipe::copyFromOriginal('copy-1', $secret, 'author-id', [], static fn(): string => 'new-row');
        $copyOfOrdinary = Recipe::copyFromOriginal('copy-2', $ordinary, 'copier-id', [], static fn(): string => 'new-row');

        // Then : le drapeau est le choix de l'auteur de chaque recette, il ne se recopie pas ; l'original ne bouge pas
        $this->assertFalse($copyOfSecret->isSecret());
        $this->assertFalse($copyOfOrdinary->isSecret());
        $this->assertTrue($secret->isSecret());
    }
}
