<?php

namespace Repas\Tests\Repas\OutputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Loader\ActiveShoppingListLines;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Application\Dto\ListedLine;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Model\User;

/**
 * Les lignes de la liste ACTIVE de l'utilisateur sur la vraie base (ADR 0029 décision 12, VOX-304) : le nom de l'ingrédient, son rayon, la quantité
 * et le nom de l'unité de la ligne ; jamais une liste envoyée, en pause ou terminée, ni celle d'un autre ; deux requêtes quel que soit le nombre de lignes.
 */
class ActiveShoppingListLinesPostgreSQLLoaderTest extends DatabaseTestCase
{
    private ActiveShoppingListLines $loader;
    private ShoppingListRepository $shoppingLists;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = static::getContainer()->get(ActiveShoppingListLines::class);
        $this->shoppingLists = static::getContainer()->get(ShoppingListRepository::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testItReturnsNullWhenTheUserHasNoActiveList(): void
    {
        // Given
        $user = $this->savedUser();

        // When / Then
        $this->assertNull($this->loader->forUser($user->getId()));
    }

    public function testItReturnsTheLinesOfTheActiveListWithNamesDepartmentQuantityAndUnit(): void
    {
        // Given : deux lignes, dont les œufs montés à 3
        $user = $this->savedUser();
        $egg = $this->savedIngredient($user, new IngredientBuilder()->isEgg());
        $milk = $this->savedIngredient($user, new IngredientBuilder()->isMilk());
        $list = new ShoppingListBuilder()->withOwner($user)->addIngredient($egg)->addIngredient($milk)->build();
        $list->addRow($egg->getSlug(), 'unite', 2);
        $this->shoppingLists->save($list);
        $this->entityManager->clear();

        // When
        $lines = $this->bySlug($this->loader->forUser($user->getId()) ?? []);

        // Then
        $this->assertCount(2, $lines);
        $this->assertEquals(
            new ListedLine($egg->getSlug(), $egg->getName(), $egg->getDepartment()->getSlug(), $egg->getDepartment()->getName(), 3.0, 'unite', 'unité'),
            $lines[$egg->getSlug()],
        );
        $this->assertSame([1.0, 'litre', 'litre'], [$lines[$milk->getSlug()]->quantity, $lines[$milk->getSlug()]->unitSlug, $lines[$milk->getSlug()]->unitName]);
    }

    public function testAnActiveListWithoutLinesIsAnEmptyListNotNull(): void
    {
        // Given
        $user = $this->savedUser();
        $this->shoppingLists->save(new ShoppingListBuilder()->withOwner($user)->build());
        $this->entityManager->clear();

        // When / Then : une liste existe, vide : « tu n'as pas de liste en cours » ne serait pas vrai
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

    /**
     * @param ShoppingListStatus $status
     */
    #[DataProvider('notActiveStatuses')]
    public function testOnlyAnActiveListIsRead(ShoppingListStatus $status): void
    {
        // Given : la seule liste n'est pas active
        $user = $this->savedUser();
        $egg = $this->savedIngredient($user, new IngredientBuilder()->isEgg());
        $this->shoppingLists->save(new ShoppingListBuilder()->withOwner($user)->addIngredient($egg)->withStatus($status)->build());
        $this->entityManager->clear();

        // When / Then
        $this->assertNull($this->loader->forUser($user->getId()));
    }

    public function testTheListOfAnotherUserIsNeverRead(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();
        $egg = $this->savedIngredient($other, new IngredientBuilder()->isEgg());
        $this->shoppingLists->save(new ShoppingListBuilder()->withOwner($other)->addIngredient($egg)->build());
        $this->entityManager->clear();

        // When / Then
        $this->assertNull($this->loader->forUser($user->getId()));
        $this->assertCount(1, $this->loader->forUser($other->getId()) ?? []);
    }

    public function testTheNumberOfQueriesDoesNotDependOnTheNumberOfLines(): void
    {
        // Given : une liste d'un ingrédient
        $user = $this->savedUser();
        $counter = static::getContainer()->get(QueryCounter::class);
        $egg = $this->savedIngredient($user, new IngredientBuilder()->isEgg());
        $list = new ShoppingListBuilder()->withOwner($user)->addIngredient($egg)->build();
        $this->shoppingLists->save($list);
        $this->entityManager->clear();
        $counter->reset();

        // When
        $this->assertCount(1, $this->loader->forUser($user->getId()) ?? []);
        $withOne = $counter->count();

        // Given : un autre utilisateur, une liste de vingt lignes
        $second = $this->savedUser();
        $twenty = new ShoppingListBuilder()->withOwner($second)->withId('liste-vingt');
        for ($index = 1; $index <= 20; ++$index) {
            $twenty->addIngredient($this->savedIngredient($second, new IngredientBuilder()->withName("ingredient numero $index")));
        }
        $this->shoppingLists->save($twenty->build());
        $this->entityManager->clear();
        $counter->reset();

        // When
        $this->assertCount(20, $this->loader->forUser($second->getId()) ?? []);
        $withTwenty = $counter->count();

        // Then : deux requêtes dans les deux cas
        $this->assertSame($withOne, $withTwenty);
        $this->assertSame(2, $withTwenty);
    }

    /**
     * @param list<ListedLine> $lines
     *
     * @return array<string, ListedLine>
     */
    private function bySlug(array $lines): array
    {
        $bySlug = [];
        foreach ($lines as $line) {
            $bySlug[$line->slug] = $line;
        }

        return $bySlug;
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }

    private function savedIngredient(User $creator, IngredientBuilder $builder): Ingredient
    {
        $ingredient = $builder->withCreator($creator, true)->build();
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }
}
