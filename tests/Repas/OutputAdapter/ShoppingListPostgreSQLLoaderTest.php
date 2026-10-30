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
use Repas\Tests\Helper\SqlBudget\QueryCounter;
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
            [new ShoppingListDTO('list-active', 'Courses du week-end', 'ACTIVE', true, false, false, false, null, new DateTimeImmutable('2026-10-12 10:00:00')->format(DATE_ATOM))],
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
        $this->saveShoppingList($owner, 'list-sent', ShoppingListStatus::SENT, null, '2026-02-15 10:00:00');

        // When
        $result = $this->shoppingListLoader->listByOwner($owner->getId());

        // Then
        $this->assertSame(
            [
                'list-active' => ['ACTIVE', true, false, false, false],
                'list-sent' => ['SENT', false, false, false, true],
                'list-paused' => ['PAUSED', false, true, false, false],
                'list-completed' => ['COMPLETED', false, false, true, false],
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

    public function testListByOwnerShowsTheCalendarYearAtTheEndOfDecember(): void
    {
        // Given : le 29 décembre 2025 est en semaine ISO 1 de 2026 ; l'année affichée est celle du calendrier
        $owner = $this->savedUser();
        $this->saveShoppingList($owner, 'list-year-end', ShoppingListStatus::ACTIVE, null, '2025-12-29 09:00:00');

        // When
        $result = $this->shoppingListLoader->listByOwner($owner->getId());

        // Then
        $this->assertSame('29 décembre 2025', $result[0]->displayName);
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
        $shoppingList->addRow($egg->getSlug(), $egg->getDefaultPurchaseUnit()->getSlug(), 5);
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
        $this->assertSame(['PAUSED', false, true, false, false], [$result->status, $result->isActive, $result->isPaused, $result->isCompleted, $result->isSent]);
        $this->assertNull($result->sentAt);
    }

    public function testGetReturnsTheVersionOfTheList(): void
    {
        // Given : une liste neuve (version 1) puis enregistrée une seconde fois (version 2)
        $owner = $this->savedUser();
        $this->saveShoppingList($owner, 'list-1', ShoppingListStatus::ACTIVE, null, '2026-10-12 10:00:00');
        $this->assertSame(1, $this->shoppingListLoader->get('list-1')->version);
        $list = $this->shoppingListRepository->findOneById('list-1');
        $list->setName('Courses');
        $this->shoppingListRepository->save($list);

        // When
        $result = $this->shoppingListLoader->get('list-1');

        // Then
        $this->assertSame(2, $result->version);
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
        $shoppingList->addRow($pasta->getSlug(), $pasta->getDefaultPurchaseUnit()->getSlug(), 499);
        $shoppingList->getRows()->find(fn($row) => $row->getIngredientSlug() === $egg->getSlug())->tick();
        $this->shoppingListRepository->save($shoppingList);

        // When
        $result = $this->shoppingListLoader->get('list-1');

        // Then
        $this->assertSame(['boulangerie', 'cereale', 'divers'], array_map(static fn(ShoppingListDepartmentDTO $d): string => $d->slug, $result->departments));
        $this->assertSame(['Pain'], $this->ingredientNames($result->departments[0]));
        $this->assertSame(['Pate'], $this->ingredientNames($result->departments[1]));
        $this->assertSame(['Lait', 'Œuf'], $this->ingredientNames($result->departments[2]));
        $this->assertSame(4, $result->rowCount);
        $this->assertEquals(new ShoppingListRowDTO($result->departments[1]->rows[0]->id, 'Pate', 500.0, 'g', false, 'pate', 'gramme', 'cereale', 'céréale'), $result->departments[1]->rows[0]);
        $eggRow = $result->departments[2]->rows[1];
        $this->assertSame(['Œuf', 1.0, '', true], [$eggRow->ingredientName, $eggRow->quantity, $eggRow->unitSymbol, $eggRow->checked]);
        $this->assertSame(['oeuf', 'unite'], [$eggRow->ingredientSlug, $eggRow->unitSlug]);
        $this->assertSame('céréale', $result->departments[1]->name);
        $this->assertSame($pasta->getDepartment()->getImage(), $result->departments[1]->image);
    }

    public function testGetReadsTheRowsWithTheirSlugsWithoutAnyExtraQuery(): void
    {
        // Given : quatre lignes, trois rayons ; les identifiants d'ingrédient et d'unité viennent de la requête des lignes (jointures déjà présentes)
        $owner = $this->savedUser();
        $ingredients = [new IngredientBuilder()->isBread()->build(), new IngredientBuilder()->isPasta()->build(), new IngredientBuilder()->isEgg()->build(), new IngredientBuilder()->isMilk()->withDepartment(new DepartmentBuilder()->isMiscellaneous())->build()];
        $builder = new ShoppingListBuilder()->withId('list-1')->withOwner($owner);
        foreach ($ingredients as $ingredient) {
            $this->persister->saveIngredient($ingredient);
            $builder->addIngredient($ingredient);
        }
        $this->shoppingListRepository->save($builder->build());
        static::getContainer()->get('doctrine')->getManager()->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->shoppingListLoader->get('list-1');

        // Then
        $this->assertSame(['SELECT shopping_list' => 1, 'SELECT shopping_list_row' => 1, 'SELECT meal' => 1], $counter->byTable());
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

    public function testAMealInheritedFromTheSecretRecipeOfAnotherUserShowsNoNameAndNoType(): void
    {
        // Given : une liste de «reader» dont un repas hérité (d'avant la garde de `addMeal`) pointe vers la recette d'un autre, d'abord ordinaire
        $reader = $this->savedUser();
        $author = $this->savedUser();
        $ownRecipe = new RecipeBuilder()->isPastaCarbonara()->withName('Mon plat')->withAuthor($reader)->build();
        $other = new RecipeBuilder()->isBasqueCake()->withName('Gâteau de mamie')->withAuthor($author)->build();
        $this->persister->saveRecipe($ownRecipe);
        $this->persister->saveRecipe($other);
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('list-1')->withOwner($reader)->addRecipe($ownRecipe)->build());
        $this->entityManager->getConnection()->executeStatement("INSERT INTO meal (id, shopping_list, recipe, serving) VALUES ('legacy-meal', 'list-1', ?, 4)", [$other->getId()]);

        // When : tant qu'elle est ordinaire, le repas hérité se lit comme avant
        $visible = $this->shoppingListLoader->get('list-1');
        // Puis son auteur la rend secrète (ADR 0033)
        $this->entityManager->getConnection()->executeStatement('UPDATE recipe SET secret = TRUE WHERE id = ?', [$other->getId()]);
        $hidden = $this->shoppingListLoader->get('list-1');

        // Then : ni son nom, ni son type, ni son identifiant ne sortent plus de la liste de «reader» ; son propre repas reste
        $this->assertSame(['Gâteau de mamie', 'Mon plat'], $this->recipeNames($visible));
        $this->assertSame(['Mon plat'], $this->recipeNames($hidden));
        $this->assertSame(['plat'], array_map(static fn(ShoppingListRecipeTypeDTO $type): string => $type->slug, $hidden->recipeTypes));
        $this->assertSame(1, $hidden->recipeCount);
        $this->assertStringNotContainsString($other->getId(), json_encode($hidden, JSON_THROW_ON_ERROR));
    }

    public function testTheSecretRecipeOfTheOwnerOfTheListStaysInIt(): void
    {
        // Given : l'auteur planifie sa propre recette secrète
        $owner = $this->savedUser();
        $secret = new RecipeBuilder()->isPastaCarbonara()->withName('Mon secret')->withAuthor($owner)->asSecret()->build();
        $this->persister->saveRecipe($secret);
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('list-1')->withOwner($owner)->addRecipe($secret)->build());

        // When & Then
        $this->assertSame(['Mon secret'], $this->recipeNames($this->shoppingListLoader->get('list-1')));
    }

    /**
     * @return list<string>
     */
    private function recipeNames(\Repas\Repas\Application\Query\GetShoppingList\ShoppingListDetailDTO $list): array
    {
        $names = [];
        foreach ($list->recipeTypes as $type) {
            foreach ($type->meals as $meal) {
                $names[] = $meal->recipeName;
            }
        }
        sort($names);

        return $names;
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
            $flags[$list->id] = [$list->status, $list->isActive, $list->isPaused, $list->isCompleted, $list->isSent];
        }

        return $flags;
    }

    public function testASentListExposesItsSentDateInTheListAndTheDetail(): void
    {
        // Given
        $owner = $this->savedUser();
        $this->shoppingListRepository->save(
            new ShoppingListBuilder()
                ->withId('list-sent')
                ->withOwner($owner)
                ->addIngredient(new IngredientBuilder()->isEgg())
                ->withStatus(ShoppingListStatus::SENT)
                ->withSentAt(new DateTimeImmutable('2026-10-18 10:30:00'))
                ->build()
        );
        $this->saveShoppingList($owner, 'list-paused', ShoppingListStatus::PAUSED, null, '2026-01-01 10:00:00');
        $expected = new DateTimeImmutable('2026-10-18 10:30:00')->format(DATE_ATOM);

        // When
        $summaries = array_column(array_map(static fn(ShoppingListDTO $dto): array => [$dto->id, $dto->sentAt], $this->shoppingListLoader->listByOwner($owner->getId())), 1, 0);
        $detail = $this->shoppingListLoader->get('list-sent');

        // Then
        $this->assertSame(['list-sent' => $expected, 'list-paused' => null], $summaries);
        $this->assertSame(['SENT', false, false, false, true, $expected], [$detail->status, $detail->isActive, $detail->isPaused, $detail->isCompleted, $detail->isSent, $detail->sentAt]);
    }

    public function testGetCurrentCanBeASentListWhenNoneIsActive(): void
    {
        // Given : la liste envoyée libère la place de la liste active, c'est alors la plus récente qui est courante
        $owner = $this->savedUser();
        $this->saveShoppingList($owner, 'list-older', ShoppingListStatus::PAUSED, null, '2026-01-01 10:00:00');
        $this->saveShoppingList($owner, 'list-sent', ShoppingListStatus::SENT, null, '2026-03-03 10:00:00');

        // When
        $result = $this->shoppingListLoader->getCurrent($owner->getId());

        // Then
        $this->assertSame(['list-sent', true], [$result->id, $result->isSent]);
    }
}
