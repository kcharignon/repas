<?php

namespace Repas\Tests\Repas\OutputAdapter;


use DateTimeImmutable;
use Repas\Repas\Application\Loader\SpokenIngredientCandidates;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\SpokenCandidate;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Model\User;

/**
 * Les ingrédients de l'utilisateur et son habitude de chacun, sur la vraie base (VOX-102) : jamais ceux d'un autre, lignes de listes (douze
 * mois) et de recettes (sans date) comptées, un nombre de requêtes qui ne dépend pas du nombre d'ingrédients.
 */
class SpokenIngredientCandidatesPostgreSQLLoaderTest extends DatabaseTestCase
{
    private SpokenIngredientCandidates $loader;
    private ShoppingListRepository $shoppingLists;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = static::getContainer()->get(SpokenIngredientCandidates::class);
        $this->shoppingLists = static::getContainer()->get(ShoppingListRepository::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testItReturnsTheOwnIngredientsWithTheirNamesAndDepartmentAndNeverThoseOfAnotherUser(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();
        $own = $this->savedIngredient($user, 'tomates pelées', new DepartmentBuilder()->isCereal());
        $this->savedIngredient($other, 'tomates cerises', new DepartmentBuilder()->isCereal());

        // When
        $candidates = $this->loader->forUser($user->getId());

        // Then : le nom, le nom normalisé et le rayon (nom affichable et slug) de l'ingrédient du seul utilisateur
        $this->assertEquals(
            [new SpokenCandidate($own->getSlug(), $own->getSluggedName(), 'tomates pelées', new DepartmentBuilder()->isCereal()->build()->getName(), 0, new DepartmentBuilder()->isCereal()->build()->getSlug())],
            $candidates,
        );
        $this->assertSame([], $this->loader->forUser($this->savedUser()->getId()));
    }

    public function testAnIngredientNeverUsedHasNoUse(): void
    {
        // Given
        $user = $this->savedUser();
        $this->savedIngredient($user, 'riz');

        // When
        $candidates = $this->loader->forUser($user->getId());

        // Then
        $this->assertSame([0], $this->usesOf($candidates));
    }

    public function testItCountsTheRowsOfTheListsAndOfTheRecipesOfTheUser(): void
    {
        // Given : le riz est dans deux listes et une recette, la farine dans une recette seule, le sel nulle part
        $user = $this->savedUser();
        $rice = $this->savedIngredient($user, 'riz');
        $flour = $this->savedIngredient($user, 'farine');
        $salt = $this->savedIngredient($user, 'sel');
        $this->savedList($user, 'liste-1', [$rice], new DateTimeImmutable('-1 month'));
        $this->savedList($user, 'liste-2', [$rice], new DateTimeImmutable('-2 days'));
        $this->savedRecipe($user, [$rice, $flour]);
        $this->savedRecipe($user, [$flour]);

        // When
        $uses = $this->usesBySlug($this->loader->forUser($user->getId()));

        // Then
        $this->assertSame([$flour->getSlug() => 2, $rice->getSlug() => 3, $salt->getSlug() => 0], $this->sorted($uses));
    }

    public function testItIgnoresTheListsOlderThanTwelveMonths(): void
    {
        // Given : le riz est dans une liste d'il y a 13 mois (hors fenêtre) et dans une liste d'il y a 11 mois
        $user = $this->savedUser();
        $rice = $this->savedIngredient($user, 'riz');
        $this->savedList($user, 'vieille-liste', [$rice], new DateTimeImmutable('-13 months'));
        $this->savedList($user, 'liste-recente', [$rice], new DateTimeImmutable('-11 months'));

        // When
        $candidates = $this->loader->forUser($user->getId());

        // Then
        $this->assertSame([1], $this->usesOf($candidates));
    }

    public function testItDoesNotCountTheListsAndTheRecipesOfAnotherUser(): void
    {
        // Given : les listes et les recettes d'un autre utilisateur portent le même slug d'ingrédient (l'ancien slug d'un commun, par exemple)
        $user = $this->savedUser();
        $other = $this->savedUser();
        $rice = $this->savedIngredient($user, 'riz');
        $theirRice = $this->savedIngredient($other, 'riz');
        $this->savedList($other, 'liste-d-un-autre', [$theirRice], new DateTimeImmutable('-1 day'));
        $this->savedRecipe($other, [$theirRice]);

        // When
        $candidates = $this->loader->forUser($user->getId());

        // Then : ni la liste ni la recette de l'autre ne comptent
        $this->assertSame([$rice->getSlug()], array_map(static fn(SpokenCandidate $candidate): string => $candidate->slug, $candidates));
        $this->assertSame([0], $this->usesOf($candidates));
    }

    public function testTheNumberOfQueriesDoesNotDependOnTheNumberOfIngredients(): void
    {
        // Given : cinq ingrédients utilisés dans une liste et une recette
        $user = $this->savedUser();
        $counter = static::getContainer()->get(QueryCounter::class);
        $five = $this->savedIngredients($user, 5);
        $this->savedList($user, 'liste-5', $five, new DateTimeImmutable('-1 day'));
        $this->savedRecipe($user, $five);
        $counter->reset();

        // When
        $this->assertCount(5, $this->loader->forUser($user->getId()));
        $withFive = $counter->count();

        // Given : cinquante de plus
        $fifty = $this->savedIngredients($user, 50, 'bis');
        $this->savedList($user, 'liste-50', $fifty, new DateTimeImmutable('-1 day'));
        $this->savedRecipe($user, $fifty);
        $counter->reset();

        // When
        $this->assertCount(55, $this->loader->forUser($user->getId()));
        $withFifty = $counter->count();

        // Then : trois requêtes au plus, les mêmes dans les deux cas
        $this->assertSame($withFive, $withFifty);
        $this->assertLessThanOrEqual(3, $withFifty);
    }

    /**
     * @param list<SpokenCandidate> $candidates
     *
     * @return list<int>
     */
    private function usesOf(array $candidates): array
    {
        return array_map(static fn(SpokenCandidate $candidate): int => $candidate->uses12m, $candidates);
    }

    /**
     * @param list<SpokenCandidate> $candidates
     *
     * @return array<string, int>
     */
    private function usesBySlug(array $candidates): array
    {
        $uses = [];
        foreach ($candidates as $candidate) {
            $uses[$candidate->slug] = $candidate->uses12m;
        }

        return $uses;
    }

    /**
     * @param array<string, int> $uses
     *
     * @return array<string, int>
     */
    private function sorted(array $uses): array
    {
        ksort($uses);

        return $uses;
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }

    private function savedIngredient(User $creator, string $name, ?DepartmentBuilder $department = null): Ingredient
    {
        $ingredient = new IngredientBuilder()->withName($name)->withDepartment($department ?? new DepartmentBuilder())->withCreator($creator, true)->build();
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }

    /**
     * @return list<Ingredient>
     */
    private function savedIngredients(User $creator, int $count, string $suffix = 'ingredient'): array
    {
        $ingredients = [];
        for ($index = 1; $index <= $count; ++$index) {
            $ingredients[] = $this->savedIngredient($creator, sprintf('%s %s %d', $suffix, 'numero', $index));
        }

        return $ingredients;
    }

    /**
     * @param list<Ingredient> $ingredients
     */
    private function savedList(User $owner, string $id, array $ingredients, DateTimeImmutable $createdAt): void
    {
        $builder = new ShoppingListBuilder()->withId($id)->withOwner($owner)->withCreatedAt($createdAt);
        foreach ($ingredients as $ingredient) {
            $builder->addIngredient($ingredient);
        }
        $this->shoppingLists->save($builder->build());
    }

    /**
     * @param list<Ingredient> $ingredients
     */
    private function savedRecipe(User $author, array $ingredients): void
    {
        $builder = new RecipeBuilder()->withAuthor($author);
        foreach ($ingredients as $ingredient) {
            $builder->addRow(new RecipeRowBuilder()->withIngredient($ingredient)->withUnit(new UnitBuilder()->isGramme())->withQuantity(100));
        }
        $recipe = $builder->build();
        $this->persister->saveRecipe($recipe);
    }
}
