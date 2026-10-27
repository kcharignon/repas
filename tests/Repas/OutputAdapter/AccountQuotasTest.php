<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Command\CopyRecipe\CopyRecipeCommand;
use Repas\Repas\Application\Command\CreateIngredient\CreateIngredientCommand;
use Repas\Repas\Application\Command\CreateIngredientConversion\CreateIngredientConversionCommand;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeCommand;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeRowSubCommand;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListCommand;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedUserIngredientsCommand;
use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\Tests\Helper\WriteBudgetTestCase;
use Repas\User\Domain\Model\User;

/**
 * Les quotas par compte sur la vraie base, avec les vrais bus : un compte écrit jusqu'à 1 000 recettes, 2 000 ingrédients et 1 000 listes à lui, et un ingrédient reçoit 50 conversions propres,
 * pas au-delà ; la copie d'une recette et l'amorçage à l'activation ne sont jamais refusés pour autant. Les lignes en masse sont écrites en SQL
 * (une instruction), les valeurs sont factices.
 */
class AccountQuotasTest extends WriteBudgetTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());
        $this->persister->saveDepartment(new DepartmentBuilder()->isBakery()->build());
        $this->persister->saveUnit(new UnitBuilder()->isGramme()->build());
        $this->persister->saveUnit(new UnitBuilder()->isKilo()->build());
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $this->user = $this->savedUser();
    }

    public function testAnIngredientIsCreatedJustBelowTheMaximumAndRefusedAtTheMaximum(): void
    {
        // Given : 1 999 ingrédients au compte, deux à un autre
        $this->bulkIngredients($this->user, Ingredient::MAX_PER_CREATOR - 1);
        $this->bulkIngredients($this->savedUser(), 2, 'other');

        // When : le deux millième passe
        $this->dispatch($this->createIngredient('Le dernier'));

        // Then : le suivant est refusé, et rien n'est écrit
        $this->assertSame(Ingredient::MAX_PER_CREATOR, $this->rowsOf('ingredient', 'creator', $this->user->getId()));
        try {
            $this->dispatch($this->createIngredient('Un de trop'));
            $this->fail('Le deux mille et unième ingrédient doit être refusé.');
        } catch (IngredientException $exception) {
            $this->assertSame([IngredientException::LIMIT_REACHED, 422], [$exception->getMessage(), $exception->getCode()]);
        }
        $this->assertSame(Ingredient::MAX_PER_CREATOR, $this->rowsOf('ingredient', 'creator', $this->user->getId()));
    }

    public function testTheQuotaOfIngredientsDoesNotBlockTheSeedingOfAnAccountThatReachedIt(): void
    {
        // Given : le compte a atteint le maximum ; le catalogue du propriétaire compte cinq ingrédients de base
        $this->bulkIngredients($this->user, Ingredient::MAX_PER_CREATOR);
        $this->savedSeedingCatalog(5);

        // When
        $this->dispatch(new SeedUserIngredientsCommand($this->user->getId(), 'essai'));

        // Then : les cinq copies sont là
        $this->assertSame(Ingredient::MAX_PER_CREATOR + 5, $this->rowsOf('ingredient', 'creator', $this->user->getId()));
    }

    public function testARecipeIsWrittenJustBelowTheMaximumAndRefusedAtTheMaximum(): void
    {
        // Given : 999 recettes au compte
        $pasta = $this->personalPasta($this->user);
        $this->bulkRecipes($this->user, Recipe::MAX_PER_AUTHOR - 1);

        // When : la millième passe
        $this->dispatch($this->createRecipe('the-last', $pasta));

        // Then : la suivante est refusée
        $this->assertSame(Recipe::MAX_PER_AUTHOR, $this->rowsOf('recipe', 'author', $this->user->getId()));
        try {
            $this->dispatch($this->createRecipe('one-too-many', $pasta));
            $this->fail('La mille et unième recette doit être refusée.');
        } catch (RecipeException $exception) {
            $this->assertSame([RecipeException::LIMIT_REACHED, 422], [$exception->getMessage(), $exception->getCode()]);
        }
        $this->assertSame(Recipe::MAX_PER_AUTHOR, $this->rowsOf('recipe', 'author', $this->user->getId()));
    }

    public function testTheCopyOfARecipeIsNotRefusedToAnAuthorWhoReachedTheMaximum(): void
    {
        // Given : le compte a ses 1 000 recettes ; un autre a écrit celle qu'il copie
        $this->bulkRecipes($this->user, Recipe::MAX_PER_AUTHOR);
        $author = $this->savedUser();
        $original = new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->build();
        $this->persister->saveRecipe($original);

        // When
        $this->dispatch(new CopyRecipeCommand($original->getId(), $this->user->getId(), 'the-copy'));

        // Then
        $this->assertSame(Recipe::MAX_PER_AUTHOR + 1, $this->rowsOf('recipe', 'author', $this->user->getId()));
        $this->assertSame($original->getId(), $this->entityManager->getConnection()->fetchOne('SELECT orignal FROM recipe WHERE id = ?', ['the-copy']));
    }

    public function testAListIsCreatedJustBelowTheMaximumAndRefusedAtTheMaximum(): void
    {
        // Given : 999 listes à garder (et une terminée, qui ne compte pas)
        $this->bulkLists($this->user, ShoppingList::MAX_PER_OWNER - 1, 'PAUSED');
        $this->bulkLists($this->user, 1, 'COMPLETED');

        // When : la millième passe (la terminée est supprimée à sa création)
        $this->dispatch(new CreateShoppingListCommand('the-last', $this->user->getId()));

        // Then : la suivante est refusée, et la liste active n'est pas mise en pause pour rien
        $this->assertSame(ShoppingList::MAX_PER_OWNER, $this->rowsOf('shopping_list', 'owner', $this->user->getId()));
        try {
            $this->dispatch(new CreateShoppingListCommand('one-too-many', $this->user->getId()));
            $this->fail('La mille et unième liste doit être refusée.');
        } catch (ShoppingListException $exception) {
            $this->assertSame([ShoppingListException::LIMIT_REACHED, 422], [$exception->getMessage(), $exception->getCode()]);
        }
        $this->assertSame('ACTIVE', $this->entityManager->getConnection()->fetchOne('SELECT status FROM shopping_list WHERE id = ?', ['the-last']));
        $this->assertSame(ShoppingList::MAX_PER_OWNER, $this->rowsOf('shopping_list', 'owner', $this->user->getId()));
    }

    public function testCompletedListsAreNotCountedAgainstTheMaximum(): void
    {
        // Given : 999 listes à garder et 300 terminées
        $this->bulkLists($this->user, ShoppingList::MAX_PER_OWNER - 1, 'PAUSED');
        $this->bulkLists($this->user, 300, 'COMPLETED');

        // When / Then : la création passe
        $this->dispatch(new CreateShoppingListCommand('new-list', $this->user->getId()));
        $this->assertSame(ShoppingList::MAX_PER_OWNER, $this->rowsOf('shopping_list', 'owner', $this->user->getId()));
    }

    public function testTheCheckStopsAtTheLimitAndReadsOneRowAtMost(): void
    {
        // Given : un compte hors norme, 3 000 recettes et 3 000 listes
        $this->bulkRecipes($this->user, 3000);
        $this->bulkLists($this->user, 3000, 'PAUSED');
        $counter = static::getContainer()->get(QueryCounter::class);
        $this->entityManager->clear();
        $counter->reset();

        // When
        $recipes = static::getContainer()->get(RecipeRepository::class)->authorHasAtLeast($this->user->getId(), Recipe::MAX_PER_AUTHOR);
        $lists = static::getContainer()->get(ShoppingListRepository::class)->ownerHasAtLeast($this->user->getId(), ShoppingList::MAX_PER_OWNER);

        // Then : une requête chacune, qui ne remonte jamais le décompte entier
        $this->assertSame([true, true, 2], [$recipes, $lists, $counter->count()]);
        foreach ($counter->statements() as $sql) {
            $this->assertStringNotContainsString('COUNT(', strtoupper($sql));
        }
    }

    public function testAConversionIsAddedJustBelowTheMaximumAndRefusedAtTheMaximum(): void
    {
        // Given : la farine de l'utilisateur a 49 conversions propres ; un autre ingrédient en a 60
        $flour = new IngredientBuilder()->isFlour()->withCreator($this->user, true)->build();
        $this->persister->saveIngredient($flour);
        $sugar = new IngredientBuilder()->isSugar()->withCreator($this->user, true)->build();
        $this->persister->saveIngredient($sugar);
        $this->persister->saveUnit(new UnitBuilder()->isGlass()->build());
        $this->bulkConversions($flour, Conversion::MAX_PER_INGREDIENT - 1);
        $this->bulkConversions($sugar, Conversion::MAX_PER_INGREDIENT + 10);

        // When : la cinquantième passe
        $this->dispatch(new CreateIngredientConversionCommand('the-last', $flour->getSlug(), 'verre', 'gramme', 130, $this->user->getId()));

        // Then : la suivante est refusée, et rien n'est écrit
        $this->assertSame(Conversion::MAX_PER_INGREDIENT, $this->rowsOf('conversion', 'ingredient', $flour->getSlug()));
        try {
            $this->dispatch(new CreateIngredientConversionCommand('one-too-many', $flour->getSlug(), 'verre', 'kilo', 0.13, $this->user->getId()));
            $this->fail('La cinquante et unième conversion doit être refusée.');
        } catch (ConversionException $exception) {
            $this->assertSame([ConversionException::LIMIT_REACHED, 422], [$exception->getMessage(), $exception->getCode()]);
        }
        $this->assertSame(Conversion::MAX_PER_INGREDIENT, $this->rowsOf('conversion', 'ingredient', $flour->getSlug()));
        $this->assertSame(0, $this->rowsOf('conversion', 'id', 'one-too-many'));
    }

    public function testTheCheckOfTheConversionsOfAnIngredientReadsOneRowAtMost(): void
    {
        // Given : un ingrédient hors norme, 3 000 conversions propres
        $flour = new IngredientBuilder()->isFlour()->withCreator($this->user, true)->build();
        $this->persister->saveIngredient($flour);
        $this->bulkConversions($flour, 3000);
        $counter = static::getContainer()->get(QueryCounter::class);
        $this->entityManager->clear();
        $counter->reset();

        // When
        $full = static::getContainer()->get(ConversionRepository::class)->ingredientHasAtLeast($flour, Conversion::MAX_PER_INGREDIENT);

        // Then : une requête, qui ne remonte jamais le décompte entier
        $this->assertSame([true, 1], [$full, $counter->count()]);
        foreach ($counter->statements() as $sql) {
            $this->assertStringNotContainsString('COUNT(', strtoupper($sql));
        }
    }

    public function testTheCheckAnswersExactlyAtTheBoundary(): void
    {
        // Given : 3 recettes au compte, une à un autre
        $this->bulkRecipes($this->user, 3);
        $this->bulkRecipes($this->savedUser(), 1, 'other');
        $recipes = static::getContainer()->get(RecipeRepository::class);

        // When / Then
        $this->assertSame([true, true, false], [
            $recipes->authorHasAtLeast($this->user->getId(), 2),
            $recipes->authorHasAtLeast($this->user->getId(), 3),
            $recipes->authorHasAtLeast($this->user->getId(), 4),
        ]);
    }

    private function dispatch(object $command): void
    {
        static::getContainer()->get(CommandBusInterface::class)->dispatch($command);
    }

    private function createIngredient(string $name): CreateIngredientCommand
    {
        return new CreateIngredientCommand($name, '', 'boulangerie', 'gramme', 'gramme', $this->user->getId(), null);
    }

    private function createRecipe(string $id, Ingredient $ingredient): CreateRecipeCommand
    {
        return new CreateRecipeCommand(
            id: $id,
            name: 'Recette ' . $id,
            serving: 4,
            authorId: $this->user->getId(),
            rows: Tab::fromArray(new CreateRecipeRowSubCommand($ingredient->getSlug(), 'gramme', 100)),
            typeSlug: 'plat',
        );
    }

    private function personalPasta(User $user): Ingredient
    {
        $pasta = new IngredientBuilder()->isPasta()->withCreator($user, true)->build();
        $this->persister->saveIngredient($pasta);

        return $pasta;
    }

    private function rowsOf(string $table, string $column, string $value): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM $table WHERE $column = ?", [$value]);
    }

    private function bulkIngredients(User $owner, int $count, string $prefix = 'bulk'): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO ingredient (slug, name, image, slugged_name, department, default_cooking_unit, default_purchase_unit, creator, compatible_units, adopted_from, is_base)"
            . " SELECT ? || '-' || ? || '-' || g, 'Vrac ' || g, '', ? || '-' || ? || '-' || g, 'boulangerie', 'gramme', 'gramme', ?, '[\"gramme\"]', NULL, FALSE"
            . ' FROM generate_series(1, ?) g',
            [$prefix, $owner->getId(), $prefix, $owner->getId(), $owner->getId(), $count],
        );
    }

    private function bulkRecipes(User $author, int $count, string $prefix = 'bulk'): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO recipe (id, name, slug, serving, author, type, orignal, version)"
            . " SELECT gen_random_uuid()::text, 'Vrac ' || g, ? || '-' || ? || '-' || g, 4, ?, 'plat', NULL, 1"
            . ' FROM generate_series(1, ?) g',
            [$prefix, $author->getId(), $author->getId(), $count],
        );
    }

    private function bulkConversions(Ingredient $ingredient, int $count): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO conversion (id, start_unit, end_unit, coefficient, ingredient)"
            . " SELECT gen_random_uuid()::text, 'gramme', 'kilo', g, ?"
            . ' FROM generate_series(1, ?) g',
            [$ingredient->getSlug(), $count],
        );
    }

    private function bulkLists(User $owner, int $count, string $status): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO shopping_list (id, owner, created_at, status, name, version)"
            . " SELECT gen_random_uuid()::text, ?, now(), ?, NULL, 1"
            . ' FROM generate_series(1, ?) g',
            [$owner->getId(), $status, $count],
        );
    }
}
