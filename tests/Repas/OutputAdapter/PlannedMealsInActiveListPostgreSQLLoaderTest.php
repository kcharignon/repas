<?php

namespace Repas\Tests\Repas\OutputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Loader\PlannedMealsInActiveList;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Application\Dto\ListedMeal;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Model\User;

/**
 * Les repas de la liste ACTIVE de l'utilisateur sur la vraie base (ADR 0029 décision 12, VOX-306) : l'identifiant et le nom de la recette et le nombre de
 * personnes ; jamais une liste envoyée, en pause ou terminée, ni celle d'un autre ; deux requêtes quel que soit le nombre de repas.
 */
class PlannedMealsInActiveListPostgreSQLLoaderTest extends DatabaseTestCase
{
    private PlannedMealsInActiveList $loader;
    private ShoppingListRepository $shoppingLists;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = static::getContainer()->get(PlannedMealsInActiveList::class);
        $this->shoppingLists = static::getContainer()->get(ShoppingListRepository::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->withServing(3)->build();
        $this->persister->saveUser($user);

        return $user;
    }

    private function savedRecipe(User $author, RecipeBuilder $builder): Recipe
    {
        $recipe = $builder->withAuthor($author)->build();
        foreach ($recipe->getRows() as $row) {
            $row->getIngredient()->setCreatorId($author->getId());
        }
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    public function testAMealInheritedFromTheSecretRecipeOfAnotherUserIsNotReturnedButTheOwnSecretRecipeIs(): void
    {
        // Given : la liste active de «reader» a un repas hérité (d'avant la garde de `addMeal`) vers la recette d'un autre, et son propre repas
        // d'une recette à lui, secrète
        $reader = $this->savedUser();
        $author = $this->savedUser();
        $own = $this->savedRecipe($reader, new RecipeBuilder()->isPastaCarbonara()->withName('Mon secret')->asSecret());
        $other = $this->savedRecipe($author, new RecipeBuilder()->isBasqueCake()->withName('Gâteau de mamie'));
        $this->shoppingLists->save(new ShoppingListBuilder()->withId('list-1')->withOwner($reader)->addRecipe($own)->build());
        $this->entityManager->getConnection()->executeStatement("INSERT INTO meal (id, shopping_list, recipe, serving) VALUES ('legacy-meal', 'list-1', ?, 4)", [$other->getId()]);
        $beforeSecret = $this->loader->forUser($reader->getId());

        // When : son auteur la rend secrète
        $this->entityManager->getConnection()->executeStatement('UPDATE recipe SET secret = TRUE WHERE id = ?', [$other->getId()]);
        $afterSecret = $this->loader->forUser($reader->getId());

        // Then : tant qu'elle est ordinaire le repas est rendu ; secrète, sa recette n'a plus de nom ici (comme une recette retirée)
        $this->assertEqualsCanonicalizing(['Gâteau de mamie', 'Mon secret'], array_map(static fn(ListedMeal $meal): string => $meal->name, $beforeSecret));
        $this->assertSame(['Mon secret'], array_map(static fn(ListedMeal $meal): string => $meal->name, $afterSecret));
    }

    public function testItReturnsNullWhenTheUserHasNoActiveList(): void
    {
        // Given
        $user = $this->savedUser();

        // When / Then
        $this->assertNull($this->loader->forUser($user->getId()));
    }

    public function testItReturnsTheMealsOfTheActiveListWithTheirRecipeNameAndServings(): void
    {
        // Given : deux repas, pour 3 personnes (celles du compte)
        $user = $this->savedUser();
        $carbonara = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $eggs = $this->savedRecipe($user, new RecipeBuilder()->isSoftBoiledEggs());
        $this->shoppingLists->save(new ShoppingListBuilder()->withOwner($user)->addRecipe($carbonara)->addRecipe($eggs)->build());
        $this->entityManager->clear();

        // When
        $meals = $this->loader->forUser($user->getId()) ?? [];

        // Then
        $this->assertEqualsCanonicalizing(
            [new ListedMeal($carbonara->getId(), $carbonara->getName(), 3), new ListedMeal($eggs->getId(), $eggs->getName(), 3)],
            $meals,
        );
    }

    public function testAnActiveListWithoutMealsIsAnEmptyListNotNull(): void
    {
        // Given
        $user = $this->savedUser();
        $this->shoppingLists->save(new ShoppingListBuilder()->withOwner($user)->build());
        $this->entityManager->clear();

        // When / Then : une liste existe, sans repas : « tu n'as pas de liste en cours » ne serait pas vrai
        $this->assertSame([], $this->loader->forUser($user->getId()));
    }

    /**
     * @return iterable<string, array{ShoppingListStatus}>
     */
    public static function notActiveStatuses(): iterable
    {
        yield 'envoyée au drive' => [ShoppingListStatus::SENT];
        yield 'en pause' => [ShoppingListStatus::PAUSED];
        yield 'terminée' => [ShoppingListStatus::COMPLETED];
    }

    #[DataProvider('notActiveStatuses')]
    public function testOnlyAnActiveListIsRead(ShoppingListStatus $status): void
    {
        // Given : la seule liste n'est pas active
        $user = $this->savedUser();
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $this->shoppingLists->save(new ShoppingListBuilder()->withOwner($user)->addRecipe($recipe)->withStatus($status)->build());
        $this->entityManager->clear();

        // When / Then
        $this->assertNull($this->loader->forUser($user->getId()));
    }

    public function testTheListOfAnotherUserIsNeverRead(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();
        $recipe = $this->savedRecipe($other, new RecipeBuilder()->isPastaCarbonara());
        $this->shoppingLists->save(new ShoppingListBuilder()->withOwner($other)->addRecipe($recipe)->build());
        $this->entityManager->clear();

        // When / Then
        $this->assertNull($this->loader->forUser($user->getId()));
        $this->assertCount(1, $this->loader->forUser($other->getId()) ?? []);
    }

    public function testTheNumberOfQueriesDoesNotDependOnTheNumberOfMeals(): void
    {
        // Given : une liste d'un repas
        $counter = static::getContainer()->get(QueryCounter::class);
        $user = $this->savedUser();
        $carbonara = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $this->shoppingLists->save(new ShoppingListBuilder()->withOwner($user)->addRecipe($carbonara)->build());
        $this->entityManager->clear();
        $counter->reset();

        // When
        $this->assertCount(1, $this->loader->forUser($user->getId()) ?? []);
        $withOne = $counter->count();

        // Given : un autre utilisateur, une liste de deux repas
        $second = $this->savedUser();
        $first = $this->savedRecipe($second, new RecipeBuilder()->isPastaCarbonara());
        $next = $this->savedRecipe($second, new RecipeBuilder()->isSoftBoiledEggs());
        $this->shoppingLists->save(new ShoppingListBuilder()->withOwner($second)->addRecipe($first)->addRecipe($next)->build());
        $this->entityManager->clear();
        $counter->reset();

        // When
        $this->assertCount(2, $this->loader->forUser($second->getId()) ?? []);

        // Then : deux requêtes dans les deux cas
        $this->assertSame(2, $withOne);
        $this->assertSame($withOne, $counter->count());
    }
}
