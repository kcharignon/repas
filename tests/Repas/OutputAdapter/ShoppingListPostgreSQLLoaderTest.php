<?php

namespace Repas\Tests\Repas\OutputAdapter;


use DateTimeImmutable;
use Repas\Repas\Application\Loader\ShoppingListLoader;
use Repas\Repas\Application\Query\GetActiveShoppingListSummary\ActiveShoppingListSummaryDTO;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListDepartmentDTO;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListMealDTO;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListRecipeTypeDTO;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListRowDTO;
use Repas\Repas\Application\Query\ListShoppingLists\ShoppingListDTO;
use Repas\Repas\Domain\Exception\MealException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;

class ShoppingListPostgreSQLLoaderTest extends DatabaseTestCase
{
    private ShoppingListRepository $shoppingListRepository;
    private UserRepository $userRepository;
    private ShoppingListLoader $shoppingListLoader;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shoppingListRepository = static::getContainer()->get(ShoppingListRepository::class);
        $this->userRepository = static::getContainer()->get(UserRepository::class);
        $this->shoppingListLoader = static::getContainer()->get(ShoppingListLoader::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testListByOwnerReturnsTheDTOOfAnActiveNamedList(): void
    {
        // Given
        $owner = $this->savedUser();
        $this->saveShoppingList($owner, 'list-active', ShoppingListStatus::ACTIVE, 'Courses du week-end', '2026-10-12 10:00:00');

        // When
        $result = $this->shoppingListLoader->listByOwner($owner->getId());

        // Then
        $this->assertEquals(
            [new ShoppingListDTO('list-active', 'Courses du week-end', 'ACTIVE', true, false, false, new DateTimeImmutable('2026-10-12 10:00:00')->format(DATE_ATOM))],
            $result,
        );
    }

    public function testListByOwnerComputesTheStatusFlags(): void
    {
        // Given
        $owner = $this->savedUser();
        $this->saveShoppingList($owner, 'list-active', ShoppingListStatus::ACTIVE, null, '2026-03-03 10:00:00');
        $this->saveShoppingList($owner, 'list-paused', ShoppingListStatus::PAUSED, null, '2026-02-02 10:00:00');
        $this->saveShoppingList($owner, 'list-completed', ShoppingListStatus::COMPLETED, null, '2026-01-01 10:00:00');

        // When
        $result = $this->shoppingListLoader->listByOwner($owner->getId());

        // Then
        $this->assertSame(
            [
                'list-active' => ['ACTIVE', true, false, false],
                'list-paused' => ['PAUSED', false, true, false],
                'list-completed' => ['COMPLETED', false, false, true],
            ],
            $this->flagsById($result),
        );
    }

    public function testListByOwnerFallsBackToTheCreationDateInFrenchWhenTheListHasNoName(): void
    {
        // Given
        $owner = $this->savedUser();
        $this->saveShoppingList($owner, 'list-unnamed', ShoppingListStatus::ACTIVE, null, '2026-10-12 10:00:00');

        // When
        $result = $this->shoppingListLoader->listByOwner($owner->getId());

        // Then
        $this->assertSame('12 octobre 2026', $result[0]->displayName);
    }

    public function testListByOwnerKeepsTheYearFormatOfTheOriginalTemplate(): void
    {
        // Given : « YYYY » est l'année ISO de la semaine, le 29 décembre 2025 est en semaine 1 de 2026 (comportement d'origine conservé)
        $owner = $this->savedUser();
        $this->saveShoppingList($owner, 'list-year-end', ShoppingListStatus::ACTIVE, null, '2025-12-29 09:00:00');

        // When
        $result = $this->shoppingListLoader->listByOwner($owner->getId());

        // Then
        $this->assertSame('29 décembre 2026', $result[0]->displayName);
    }

    public function testListByOwnerIsOrderedFromTheMostRecentToTheOldest(): void
    {
        // Given
        $owner = $this->savedUser();
        $this->saveShoppingList($owner, 'list-middle', ShoppingListStatus::PAUSED, null, '2026-02-02 10:00:00');
        $this->saveShoppingList($owner, 'list-oldest', ShoppingListStatus::COMPLETED, null, '2026-01-01 10:00:00');
        $this->saveShoppingList($owner, 'list-newest', ShoppingListStatus::ACTIVE, null, '2026-03-03 10:00:00');

        // When
        $result = $this->shoppingListLoader->listByOwner($owner->getId());

        // Then
        $this->assertSame(['list-newest', 'list-middle', 'list-oldest'], array_map(static fn(ShoppingListDTO $list): string => $list->id, $result));
    }

    public function testListByOwnerDoesNotReturnTheListsOfAnotherUser(): void
    {
        // Given
        $owner = $this->savedUser();
        $other = $this->savedUser();
        $this->saveShoppingList($owner, 'list-mine', ShoppingListStatus::ACTIVE, null, '2026-03-03 10:00:00');
        $this->saveShoppingList($other, 'list-theirs', ShoppingListStatus::ACTIVE, null, '2026-03-03 10:00:00');

        // When
        $result = $this->shoppingListLoader->listByOwner($owner->getId());

        // Then
        $this->assertSame(['list-mine'], array_map(static fn(ShoppingListDTO $list): string => $list->id, $result));
    }

    public function testListByOwnerReturnsNothingForAUserWithoutList(): void
    {
        // Given
        $owner = $this->savedUser();

        // When
        $result = $this->shoppingListLoader->listByOwner($owner->getId());

        // Then
        $this->assertSame([], $result);
    }

    public function testGetActiveSummaryReturnsAnEmptySummaryWithoutActiveList(): void
    {
        // Given
        $owner = $this->savedUser();
        $this->saveShoppingList($owner, 'list-paused', ShoppingListStatus::PAUSED, null, '2026-03-03 10:00:00');

        // When
        $result = $this->shoppingListLoader->getActiveSummary($owner->getId());

        // Then
        $this->assertEquals(new ActiveShoppingListSummaryDTO(null, [], []), $result);
    }

    public function testGetActiveSummaryReturnsQuantitiesAndPlannedRecipesOfTheActiveList(): void
    {
        // Given
        $owner = $this->savedUser();
        $egg = new IngredientBuilder()->isEgg()->build();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $this->persister->saveIngredient($egg);
        $this->persister->saveIngredient($pasta);
        $recipe = new RecipeBuilder()->isSoftBoiledEggs()->withAuthor($owner)->build();
        $this->persister->saveRecipe($recipe);
        $shoppingList = new ShoppingListBuilder()->withId('list-active')->withOwner($owner)
            ->addRecipe($recipe)->addIngredient($egg)->addIngredient($pasta)->build();
        $shoppingList->addRow($egg, 5);
        $this->shoppingListRepository->save($shoppingList);

        // When
        $result = $this->shoppingListLoader->getActiveSummary($owner->getId());

        // Then
        $this->assertEquals(
            new ActiveShoppingListSummaryDTO('list-active', [$egg->getSlug() => 6.0, $pasta->getSlug() => 1.0], [$recipe->getId()]),
            $result,
        );
    }

    public function testGetActiveSummaryIgnoresInactiveListsAndOtherUsers(): void
    {
        // Given
        $owner = $this->savedUser();
        $other = $this->savedUser();
        $egg = new IngredientBuilder()->isEgg()->build();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $milk = new IngredientBuilder()->isMilk()->build();
        foreach ([$egg, $pasta, $milk] as $ingredient) {
            $this->persister->saveIngredient($ingredient);
        }
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('list-active')->withOwner($owner)->addIngredient($egg)->build());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('list-paused')->withOwner($owner)->withStatus(ShoppingListStatus::PAUSED)->addIngredient($pasta)->build());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('list-theirs')->withOwner($other)->addIngredient($milk)->build());

        // When
        $result = $this->shoppingListLoader->getActiveSummary($owner->getId());

        // Then
        $this->assertEquals(new ActiveShoppingListSummaryDTO('list-active', [$egg->getSlug() => 1.0], []), $result);
    }

    public function testGetReturnsTheIdentityOfTheList(): void
    {
        // Given
        $owner = $this->savedUser();
        $this->saveShoppingList($owner, 'list-1', ShoppingListStatus::PAUSED, null, '2026-10-12 10:00:00');

        // When
        $result = $this->shoppingListLoader->get('list-1');

        // Then
        $this->assertSame('list-1', $result->id);
        $this->assertNull($result->name);
        $this->assertSame('Liste du 12 octobre 2026', $result->displayName);
        $this->assertSame('12 octobre 2026', $result->dateLabel);
        $this->assertSame(new DateTimeImmutable('2026-10-12 10:00:00')->format(DATE_ATOM), $result->createdAt);
        $this->assertSame(['PAUSED', false, true, false], [$result->status, $result->isActive, $result->isPaused, $result->isCompleted]);
    }

    public function testGetUsesTheNameGivenByTheUser(): void
    {
        // Given
        $owner = $this->savedUser();
        $this->saveShoppingList($owner, 'list-1', ShoppingListStatus::ACTIVE, 'Courses du week-end', '2026-10-12 10:00:00');

        // When
        $result = $this->shoppingListLoader->get('list-1');

        // Then
        $this->assertSame('Courses du week-end', $result->name);
        $this->assertSame('Courses du week-end', $result->displayName);
        $this->assertSame('12 octobre 2026', $result->dateLabel);
    }

    public function testGetOfAnEmptyListHasNoGroup(): void
    {
        // Given
        $owner = $this->savedUser();
        $this->saveShoppingList($owner, 'list-1', ShoppingListStatus::ACTIVE, null, '2026-10-12 10:00:00');

        // When
        $result = $this->shoppingListLoader->get('list-1');

        // Then
        $this->assertSame([[], [], 0, 0], [$result->departments, $result->recipeTypes, $result->rowCount, $result->recipeCount]);
    }

    public function testGetGroupsTheRowsByDepartmentSortedBySlug(): void
    {
        // Given : rayons « boulangerie » < « cereale » < « divers », lignes triées par slug d'ingrédient dans un rayon
        $owner = $this->savedUser();
        $bread = new IngredientBuilder()->isBread()->build();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $egg = new IngredientBuilder()->isEgg()->build();
        $milk = new IngredientBuilder()->isMilk()->withDepartment(new DepartmentBuilder()->isMiscellaneous())->build();
        foreach ([$bread, $pasta, $egg, $milk] as $ingredient) {
            $this->persister->saveIngredient($ingredient);
        }
        $shoppingList = new ShoppingListBuilder()->withId('list-1')->withOwner($owner)
            ->addIngredient($egg)->addIngredient($milk)->addIngredient($pasta)->addIngredient($bread)->build();
        $shoppingList->addRow($pasta, 499);
        $shoppingList->getRows()->find(fn($row) => $row->getIngredient()->isEqual($egg))->tick();
        $this->shoppingListRepository->save($shoppingList);

        // When
        $result = $this->shoppingListLoader->get('list-1');

        // Then
        $this->assertSame(['boulangerie', 'cereale', 'divers'], array_map(static fn(ShoppingListDepartmentDTO $d): string => $d->slug, $result->departments));
        $this->assertSame(['pain'], $this->ingredientNames($result->departments[0]));
        $this->assertSame(['pate'], $this->ingredientNames($result->departments[1]));
        $this->assertSame(['lait', 'œuf'], $this->ingredientNames($result->departments[2]));
        $this->assertSame(4, $result->rowCount);
        $this->assertEquals(new ShoppingListRowDTO($result->departments[1]->rows[0]->id, 'pate', 500.0, 'g', false), $result->departments[1]->rows[0]);
        $eggRow = $result->departments[2]->rows[1];
        $this->assertSame(['œuf', 1.0, '', true], [$eggRow->ingredientName, $eggRow->quantity, $eggRow->unitSymbol, $eggRow->checked]);
        $this->assertSame('céréale', $result->departments[1]->name);
        $this->assertSame($pasta->getDepartment()->getImage(), $result->departments[1]->image);
    }

    public function testGetGroupsTheMealsByRecipeTypeInDisplayOrder(): void
    {
        // Given : plat (ordre 2) avant dessert (ordre 3), repas d'un type triés par nom de recette
        $owner = $this->savedUser();
        $second = new RecipeBuilder()->isPastaCarbonara()->withName('zzz second plat')->withAuthor($owner)->build();
        $first = new RecipeBuilder()->isSoftBoiledEggs()->withName('aaa premier plat')->withAuthor($owner)->build();
        $dessert = new RecipeBuilder()->isBasqueCake()->withName('mmm dessert')->withAuthor($owner)->build();
        foreach ([$second, $first, $dessert] as $recipe) {
            $this->persister->saveRecipe($recipe);
        }
        $shoppingList = new ShoppingListBuilder()->withId('list-1')->withOwner($owner)
            ->addRecipe($dessert)->addRecipe($second)->addRecipe($first)->build();
        $this->shoppingListRepository->save($shoppingList);
        $mealOf = static fn(ShoppingList $list, $recipe) => $list->getMeals()->find(fn($meal) => $meal->hasRecipe($recipe));

        // When
        $result = $this->shoppingListLoader->get('list-1');

        // Then
        $this->assertSame(['plat', 'dessert'], array_map(static fn(ShoppingListRecipeTypeDTO $t): string => $t->slug, $result->recipeTypes));
        $this->assertSame(3, $result->recipeCount);
        $this->assertEquals(
            [
                new ShoppingListMealDTO($mealOf($shoppingList, $first)->getId(), $first->getId(), 'aaa premier plat', 4),
                new ShoppingListMealDTO($mealOf($shoppingList, $second)->getId(), $second->getId(), 'zzz second plat', 4),
            ],
            $result->recipeTypes[0]->meals,
        );
        $this->assertSame(['mmm dessert'], array_map(static fn(ShoppingListMealDTO $m): string => $m->recipeName, $result->recipeTypes[1]->meals));
        $this->assertSame('dessert', $result->recipeTypes[1]->name);
        $this->assertSame($dessert->getType()->getImage(), $result->recipeTypes[1]->image);
    }

    public function testGetFailsWhenTheListDoesNotExist(): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListException::shoppingListNotFound('unknown-list'));

        // When
        $this->shoppingListLoader->get('unknown-list');
    }

    public function testGetCurrentReturnsTheActiveListEvenWhenAnotherIsMoreRecent(): void
    {
        // Given
        $owner = $this->savedUser();
        $this->saveShoppingList($owner, 'list-active', ShoppingListStatus::ACTIVE, null, '2026-01-01 10:00:00');
        $this->saveShoppingList($owner, 'list-newer-paused', ShoppingListStatus::PAUSED, null, '2026-03-03 10:00:00');

        // When
        $result = $this->shoppingListLoader->getCurrent($owner->getId());

        // Then
        $this->assertSame('list-active', $result->id);
    }

    public function testGetCurrentFallsBackOnTheMostRecentListWithoutActiveOne(): void
    {
        // Given
        $owner = $this->savedUser();
        $this->saveShoppingList($owner, 'list-older', ShoppingListStatus::COMPLETED, null, '2026-01-01 10:00:00');
        $this->saveShoppingList($owner, 'list-newer', ShoppingListStatus::PAUSED, null, '2026-03-03 10:00:00');

        // When
        $result = $this->shoppingListLoader->getCurrent($owner->getId());

        // Then
        $this->assertSame('list-newer', $result->id);
    }

    public function testGetCurrentReturnsNullWithoutAnyListAndIgnoresOtherUsers(): void
    {
        // Given
        $owner = $this->savedUser();
        $this->saveShoppingList($this->savedUser(), 'list-theirs', ShoppingListStatus::ACTIVE, null, '2026-03-03 10:00:00');

        // When
        $result = $this->shoppingListLoader->getCurrent($owner->getId());

        // Then
        $this->assertNull($result);
    }

    public function testGetByMealIdReturnsTheListOfTheMeal(): void
    {
        // Given
        $owner = $this->savedUser();
        $recipe = new RecipeBuilder()->isSoftBoiledEggs()->withAuthor($owner)->build();
        $this->persister->saveRecipe($recipe);
        $shoppingList = new ShoppingListBuilder()->withId('list-1')->withOwner($owner)->addRecipe($recipe)->build();
        $this->saveShoppingList($owner, 'list-other', ShoppingListStatus::PAUSED, null, '2026-03-03 10:00:00');
        $this->shoppingListRepository->save($shoppingList);
        $mealId = $shoppingList->getMeals()->reset()->getId();

        // When
        $result = $this->shoppingListLoader->getByMealId($mealId);

        // Then
        $this->assertSame('list-1', $result->id);
        $this->assertSame($mealId, $result->recipeTypes[0]->meals[0]->id);
    }

    public function testGetByMealIdFailsWhenTheMealDoesNotExist(): void
    {
        // Then
        $this->expectExceptionObject(MealException::notFound());

        // When
        $this->shoppingListLoader->getByMealId('unknown-meal');
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);

        return $user;
    }

    private function saveShoppingList(User $owner, string $id, ShoppingListStatus $status, ?string $name, string $createdAt): void
    {
        $this->shoppingListRepository->save(
            new ShoppingListBuilder()
                ->withId($id)
                ->withOwner($owner)
                ->withStatus($status)
                ->withName($name)
                ->withCreatedAt(new DateTimeImmutable($createdAt))
                ->build()
        );
    }

    /**
     * @return list<string>
     */
    private function ingredientNames(ShoppingListDepartmentDTO $department): array
    {
        return array_map(static fn(ShoppingListRowDTO $row): string => $row->ingredientName, $department->rows);
    }

    /**
     * @param list<ShoppingListDTO> $shoppingLists
     * @return array<string, array{string, bool, bool, bool}>
     */
    private function flagsById(array $shoppingLists): array
    {
        $flags = [];
        foreach ($shoppingLists as $list) {
            $flags[$list->id] = [$list->status, $list->isActive, $list->isPaused, $list->isCompleted];
        }

        return $flags;
    }
}
