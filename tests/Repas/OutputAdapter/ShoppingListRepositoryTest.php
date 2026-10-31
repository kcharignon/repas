<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledUuidGenerator;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\RepasAssert;
use Repas\User\Domain\Model\User;

/**
 * Chaque test crée ses utilisateurs, recettes et listes (la base de test est vide au départ).
 */
class ShoppingListRepositoryTest extends DatabaseTestCase
{
    private ShoppingListRepository $shoppingListRepository;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shoppingListRepository = static::getContainer()->get(ShoppingListRepository::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testCRUD(): void
    {
        // Arrange
        $user = $this->savedUser();
        $firstRecipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $secondRecipe = $this->savedRecipe($user, new RecipeBuilder()->isSoftBoiledEggs());
        $shoppingList = new ShoppingListBuilder()
            ->withOwner($user)
            ->addRecipe($firstRecipe)
            ->build()
        ;

        // Act
        $this->shoppingListRepository->save($shoppingList);

        // Assert
        $actual = $this->shoppingListRepository->findOneById($shoppingList->getId());
        RepasAssert::assertShoppingList($shoppingList, $actual);

        // Arrange
        $shoppingList->addMeal('second-meal-id', $secondRecipe, 4);

        // Act
        $this->shoppingListRepository->save($shoppingList);

        //Assert
        $actual = $this->shoppingListRepository->findOneById($shoppingList->getId());
        RepasAssert::assertShoppingList($shoppingList, $actual);

        // Arrange
        $shoppingList->removeMeal($firstRecipe);

        // Act
        $this->shoppingListRepository->save($shoppingList);

        // Assert
        $actual = $this->shoppingListRepository->findOneById($shoppingList->getId());
        RepasAssert::assertShoppingList($shoppingList, $actual);

        // Arrange
        foreach ($secondRecipe->getRows() as $recipeRow) {
            $shoppingList->addRow($recipeRow->getIngredient()->getSlug(), $recipeRow->getIngredient()->getDefaultPurchaseUnit()->getSlug(), $recipeRow->getQuantity());
        }

        // Act
        $this->shoppingListRepository->save($shoppingList);

        // Assert
        $actual = $this->shoppingListRepository->findOneById($shoppingList->getId());
        RepasAssert::assertShoppingList($shoppingList, $actual);

        // Act
        $this->shoppingListRepository->delete($shoppingList);

        // Assert
        $this->expectExceptionObject(ShoppingListException::shoppingListNotFound($shoppingList->getId()));
        $actual = $this->shoppingListRepository->findOneById($shoppingList->getId());
    }

    public function testFindByOwner(): void
    {
        // Arrange
        $user = $this->savedUser();
        $other = $this->savedUser();
        $expectedIds = [];
        foreach (ShoppingListStatus::cases() as $status) {
            $expectedIds[] = $this->savedShoppingList($user, $status)->getId();
        }
        $this->savedShoppingList($other, ShoppingListStatus::ACTIVE);

        // Act
        $shoppingLists = $this->shoppingListRepository->findByOwner($user->getId());

        // Assert
        RepasAssert::assertTabType(Tab::newEmptyTyped(ShoppingList::class), $shoppingLists);
        $this->assertCount(count(ShoppingListStatus::cases()), $shoppingLists);
        $this->assertEqualsCanonicalizing($expectedIds, $this->idsOf($shoppingLists));
        foreach ($shoppingLists as $shoppingList) {
            $this->assertSame($user->getId(), $shoppingList->getOwnerId());
        }
    }

    public function testFindOneActivateByOwner(): void
    {
        // Arrange
        $user = $this->savedUser();
        $this->savedShoppingList($user, ShoppingListStatus::PAUSED);
        $this->savedShoppingList($user, ShoppingListStatus::COMPLETED);
        $active = $this->savedShoppingList($user, ShoppingListStatus::ACTIVE);

        // Act
        $shoppingList = $this->shoppingListRepository->findOneActivateByOwner($user->getId());

        // Assert
        $this->assertInstanceOf(ShoppingList::class, $shoppingList);
        $this->assertSame($active->getId(), $shoppingList->getId());
        $this->assertEquals(ShoppingListStatus::ACTIVE, $shoppingList->getStatus());
        $this->assertSame($user->getId(), $shoppingList->getOwnerId());
    }

    public function testFindOneActivateByOwnerReturnsNullWithoutActiveList(): void
    {
        // Arrange
        $user = $this->savedUser();
        $this->savedShoppingList($user, ShoppingListStatus::PAUSED);

        // Act
        $shoppingList = $this->shoppingListRepository->findOneActivateByOwner($user->getId());

        // Assert
        $this->assertNull($shoppingList);
    }

    public function testHasActiveShoppingList(): void
    {
        // Arrange
        $user = $this->savedUser();
        $this->savedShoppingList($user, ShoppingListStatus::PAUSED);
        $this->savedShoppingList($user, ShoppingListStatus::ACTIVE);

        // Act & Assert
        $this->assertTrue($this->shoppingListRepository->hasActiveShoppingList($user->getId()));
    }

    public function testHasNoActiveShoppingListWithOnlyPausedOrOtherUsersLists(): void
    {
        // Arrange
        $user = $this->savedUser();
        $this->savedShoppingList($user, ShoppingListStatus::PAUSED);
        $this->savedShoppingList($user, ShoppingListStatus::COMPLETED);
        $this->savedShoppingList($this->savedUser(), ShoppingListStatus::ACTIVE);

        // Act & Assert
        $this->assertFalse($this->shoppingListRepository->hasActiveShoppingList($user->getId()));
    }

    public function testIsIngredientUsedTellsIfAnyShoppingListHoldsTheIngredient(): void
    {
        // Arrange
        $user = $this->savedUser();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $this->persister->saveIngredient($pasta);
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->addIngredient($pasta)->build());
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->persister->saveIngredient($egg);

        // Act & Assert
        $this->assertTrue($this->shoppingListRepository->isIngredientUsed($pasta->getSlug()));
        $this->assertFalse($this->shoppingListRepository->isIngredientUsed($egg->getSlug()));
    }

    public function testFindIdsByOwnerAndStatus(): void
    {
        // Arrange
        $user = $this->savedUser();
        $status = ShoppingListStatus::ACTIVE;
        $active = $this->savedShoppingList($user, ShoppingListStatus::ACTIVE);
        $this->savedShoppingList($user, ShoppingListStatus::PAUSED);
        $this->savedShoppingList($this->savedUser(), ShoppingListStatus::ACTIVE);

        // Act
        $ids = $this->shoppingListRepository->findIdsByOwnerAndStatus($user->getId(), $status);

        // Assert
        $this->assertSame([$active->getId()], $ids);
    }

    public function testFindOneByMealId(): void
    {
        // Arrange
        $user = $this->savedUser();
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $generator = new ControlledUuidGenerator(['meal-id-0']);
        $shoppingList = new ShoppingListBuilder($generator)->withOwner($user)->addRecipe($recipe)->build();
        $this->shoppingListRepository->save($shoppingList);

        // Act
        $actual = $this->shoppingListRepository->findOneByMealId('meal-id-0');

        // Assert
        RepasAssert::assertShoppingList($shoppingList, $actual);
    }

    public function testIsRecipePlannedTellsIfAnyMealUsesTheRecipe(): void
    {
        // Arrange
        $user = $this->savedUser();
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $otherRecipe = $this->savedRecipe($user, new RecipeBuilder()->isSoftBoiledEggs());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->addRecipe($recipe)->build());

        // Act & Assert
        $this->assertTrue($this->shoppingListRepository->isRecipePlanned($recipe->getId()));
        $this->assertFalse($this->shoppingListRepository->isRecipePlanned($otherRecipe->getId()));
    }

    public function testEverySaveOfAnExistingListIncreasesItsVersion(): void
    {
        // Arrange
        $user = $this->savedUser();
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $shoppingList = $this->savedShoppingList($user, ShoppingListStatus::ACTIVE);
        $this->assertSame(1, $this->shoppingListRepository->findOneById($shoppingList->getId())->getVersion());

        // Act : seule une entité liée change (un repas), la ligne de la liste elle-même n'a pas bougé
        $shoppingList->addMeal('meal-id', $recipe, 4);
        $this->shoppingListRepository->save($shoppingList);

        // Assert
        $this->assertSame(2, $this->shoppingListRepository->findOneById($shoppingList->getId())->getVersion());

        // Act : un champ de la liste change
        $shoppingList->setName('Courses');
        $this->shoppingListRepository->save($shoppingList);

        // Assert
        $this->assertSame(3, $this->shoppingListRepository->findOneById($shoppingList->getId())->getVersion());
    }

    public function testSavingAListChangedByAnotherRequestIsRefusedAndWritesNothing(): void
    {
        // Arrange
        $user = $this->savedUser();
        $shoppingList = $this->savedShoppingList($user, ShoppingListStatus::ACTIVE);
        $loaded = $this->shoppingListRepository->findOneById($shoppingList->getId());
        // Une autre requête enregistre la liste après notre lecture (hors de l'identity map de celle-ci)
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE shopping_list SET version = version + 1, name = :name WHERE id = :id',
            ['name' => "Nom de l'autre", 'id' => $shoppingList->getId()],
        );
        $loaded->setName('Mon nom');

        // Assert
        $this->expectExceptionObject(ShoppingListException::concurrentModification($shoppingList->getId()));

        // Act
        try {
            $this->shoppingListRepository->save($loaded);
        } finally {
            $this->entityManager->clear();
            $after = $this->shoppingListRepository->findOneById($shoppingList->getId());
            $this->assertSame("Nom de l'autre", $after->getName());
            $this->assertSame(2, $after->getVersion());
        }
    }

    public function testTwoCopiesOfTheSameListInOneRequestDoNotBlockEachOther(): void
    {
        // Arrange : deux chargements dans la même requête, comme un handler et le listener qui recharge l'agrégat
        $user = $this->savedUser();
        $shoppingList = $this->savedShoppingList($user, ShoppingListStatus::ACTIVE);
        $first = $this->shoppingListRepository->findOneById($shoppingList->getId());
        $second = $this->shoppingListRepository->findOneById($shoppingList->getId());
        $first->setName('Premier');
        $second->setName('Second');

        // Act
        $this->shoppingListRepository->save($first);
        $this->shoppingListRepository->save($second);

        // Assert : le dernier enregistrement gagne, la version a augmenté deux fois
        $loaded = $this->shoppingListRepository->findOneById($shoppingList->getId());
        $this->assertSame(3, $loaded->getVersion());
        $this->assertSame('Second', $loaded->getName());
    }

    /**
     * @param Tab<ShoppingList> $shoppingLists
     * @return list<string>
     */
    private function idsOf(Tab $shoppingLists): array
    {
        return array_values(array_map(static fn(ShoppingList $list): string => $list->getId(), $shoppingLists->toArray()));
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }

    private function savedRecipe(User $author, RecipeBuilder $builder): Recipe
    {
        $recipe = $builder->withAuthor($author)->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    private function savedShoppingList(User $owner, ShoppingListStatus $status): ShoppingList
    {
        $shoppingList = new ShoppingListBuilder()->withOwner($owner)->withStatus($status)->build();
        $this->shoppingListRepository->save($shoppingList);

        return $shoppingList;
    }

    public function testHasSentShoppingListTellsIfTheUserHasAListSentToTheDrive(): void
    {
        // Arrange : l'un a une liste envoyée parmi d'autres, l'autre aucune, le troisième n'a que des listes non envoyées
        $withSent = $this->savedUser();
        $this->savedShoppingList($withSent, ShoppingListStatus::COMPLETED);
        $this->savedShoppingList($withSent, ShoppingListStatus::SENT);
        $withoutSent = $this->savedUser();
        $this->savedShoppingList($withoutSent, ShoppingListStatus::ACTIVE);
        $this->savedShoppingList($withoutSent, ShoppingListStatus::PAUSED);
        $this->savedShoppingList($withoutSent, ShoppingListStatus::COMPLETED);

        // Act & Assert
        $this->assertTrue($this->shoppingListRepository->hasSentShoppingList($withSent->getId()));
        $this->assertFalse($this->shoppingListRepository->hasSentShoppingList($withoutSent->getId()));
        $this->assertFalse($this->shoppingListRepository->hasSentShoppingList($this->savedUser()->getId()));
        $this->assertFalse($this->shoppingListRepository->hasSentShoppingList('unknown-id'));
    }

    public function testIsSentTellsIfTheListIsSentToTheDrive(): void
    {
        // Arrange
        $user = $this->savedUser();
        $sent = $this->savedShoppingList($user, ShoppingListStatus::SENT);
        $active = $this->savedShoppingList($user, ShoppingListStatus::ACTIVE);
        $completed = $this->savedShoppingList($user, ShoppingListStatus::COMPLETED);

        // Act & Assert
        $this->assertTrue($this->shoppingListRepository->isSent($sent->getId()));
        $this->assertFalse($this->shoppingListRepository->isSent($active->getId()));
        $this->assertFalse($this->shoppingListRepository->isSent($completed->getId()));
        $this->assertFalse($this->shoppingListRepository->isSent('unknown-id'));
    }

    public function testOwnerIdOfTellsWhoOwnsTheListInOneQueryAndNullForAnUnknownList(): void
    {
        // Arrange
        $user = $this->savedUser();
        $list = $this->savedShoppingList($user, ShoppingListStatus::SENT);
        static::getContainer()->get('doctrine.orm.entity_manager')->clear();
        $counter = static::getContainer()->get(\Repas\Tests\Helper\SqlBudget\QueryCounter::class);
        $counter->reset();

        // Act & Assert
        $this->assertSame($user->getId(), $this->shoppingListRepository->ownerIdOf($list->getId()));
        $this->assertSame(['SELECT shopping_list' => 1], $counter->byTable());
        $this->assertNull($this->shoppingListRepository->ownerIdOf('unknown-id'));
    }

    public function testSendingAndReopeningAListIsPersistedWithItsSentDate(): void
    {
        // Arrange
        $user = $this->savedUser();
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->addIngredient(new IngredientBuilder()->isEgg())->build();
        $this->persister->saveIngredient(new IngredientBuilder()->isEgg()->build());
        $this->shoppingListRepository->save($shoppingList);
        $sentAt = new \DateTimeImmutable('2026-10-18 10:30:00');

        // Act
        $shoppingList->send($sentAt);
        $this->shoppingListRepository->save($shoppingList);

        // Assert
        $loaded = $this->shoppingListRepository->findOneById($shoppingList->getId());
        $this->assertSame(ShoppingListStatus::SENT, $loaded->getStatus());
        $this->assertSame($sentAt->format(DATE_ATOM), $loaded->getSentAt()?->format(DATE_ATOM));
        $this->assertTrue($this->shoppingListRepository->isSent($shoppingList->getId()));
        $this->assertSame(2, $loaded->getVersion());

        // Act
        $loaded->reopen();
        $this->shoppingListRepository->save($loaded);

        // Assert
        $reopened = $this->shoppingListRepository->findOneById($shoppingList->getId());
        $this->assertSame(ShoppingListStatus::ACTIVE, $reopened->getStatus());
        $this->assertNull($reopened->getSentAt());
        $this->assertFalse($this->shoppingListRepository->isSent($shoppingList->getId()));
        $this->assertSame(3, $reopened->getVersion());
    }

    public function testTheListTypeAndThePlannedGroupOfAMealRoundTripThroughTheDatabase(): void
    {
        // Arrange : une liste d'un type, avec un repas d'une planification groupée et un repas planifié seul (ADR 0036)
        $user = $this->savedUser();
        $first = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $second = $this->savedRecipe($user, new RecipeBuilder()->isSoftBoiledEggs());
        $typed = new ShoppingListBuilder()->withOwner($user)->withListTypeId('type-1')->addRecipe($first)->addRecipe($second)->build();
        $main = new ShoppingListBuilder()->withOwner($user)->withStatus(ShoppingListStatus::PAUSED)->build();
        $this->shoppingListRepository->save($typed);
        $this->shoppingListRepository->save($main);
        $this->entityManager->getConnection()->executeStatement("UPDATE meal SET planned_group_id = 'group-1' WHERE id = ?", [$typed->getMeals()->reset()->getId()]);
        $this->entityManager->clear();

        // Act
        $loaded = $this->shoppingListRepository->findOneById($typed->getId());

        // Assert : lus en base (l'identity map est vide)
        $this->assertSame('type-1', $loaded->getListTypeId());
        $this->assertNull($this->shoppingListRepository->findOneById($main->getId())->getListTypeId());
        $plannedGroups = array_map(fn($meal) => $meal->getPlannedGroupId(), $loaded->getMeals()->toArray());
        sort($plannedGroups);
        $this->assertSame([null, 'group-1'], $plannedGroups);

        // Act : une modification enregistre la liste sans rien perdre
        $loaded->setName('Boucherie');
        $this->shoppingListRepository->save($loaded);
        $this->entityManager->clear();

        // Assert
        $this->assertSame('type-1', $this->shoppingListRepository->findOneById($typed->getId())->getListTypeId());
        $this->assertSame(['group-1'], $this->entityManager->getConnection()->fetchFirstColumn('SELECT planned_group_id FROM meal WHERE planned_group_id IS NOT NULL'));
    }

    public function testDetachingAListTypeUnsetsTheTypeOfItsListsPausesTheCurrentOneAndBumpsItsVersion(): void
    {
        // Arrange : quatre listes du type dans quatre statuts, une liste d'un autre type, la liste principale en cours et celle d'un autre compte
        $user = $this->savedUser();
        $other = $this->savedUser();
        $lists = [];
        foreach (['active' => ShoppingListStatus::ACTIVE, 'sent' => ShoppingListStatus::SENT, 'paused' => ShoppingListStatus::PAUSED, 'completed' => ShoppingListStatus::COMPLETED] as $label => $status) {
            $lists[$label] = new ShoppingListBuilder()->withOwner($user)->withListTypeId('type-1')->withStatus($status)->withName($label)->build();
        }
        $lists['other-type'] = new ShoppingListBuilder()->withOwner($user)->withListTypeId('type-2')->build();
        $lists['main'] = new ShoppingListBuilder()->withOwner($user)->build();
        $lists['someone-else'] = new ShoppingListBuilder()->withOwner($other)->withListTypeId('type-1')->build();
        foreach ($lists as $list) {
            $this->shoppingListRepository->save($list);
        }
        $this->entityManager->clear();
        $counter = static::getContainer()->get(\Repas\Tests\Helper\SqlBudget\QueryCounter::class);
        $counter->reset();

        // Act
        $this->shoppingListRepository->detachFromListType($user->getId(), 'type-1');

        // Assert : une seule instruction, aucune liste hydratée
        $this->assertSame(['UPDATE shopping_list' => 1], $counter->byTable());
        $rows = [];
        foreach ($this->entityManager->getConnection()->fetchAllAssociative('SELECT name, list_type_id, status, version FROM shopping_list') as $row) {
            $rows[$row['name'] ?? ($row['list_type_id'] === 'type-2' ? 'other-type' : 'main-or-else')][] = [$row['list_type_id'], $row['status'], (int) $row['version']];
        }
        $this->assertSame([null, 'PAUSED', 2], $rows['active'][0], 'la liste en cours passe en pause, version +1');
        $this->assertSame([null, 'SENT', 1], $rows['sent'][0], 'une liste envoyée garde son statut');
        $this->assertSame([null, 'PAUSED', 1], $rows['paused'][0]);
        $this->assertSame([null, 'COMPLETED', 1], $rows['completed'][0]);
        $this->assertSame(['type-2', 'ACTIVE', 1], $rows['other-type'][0], "la liste d'un autre type n'est pas touchée");
        $this->assertContains(['type-1', 'ACTIVE', 1], $rows['main-or-else'], "la liste d'un autre compte n'est pas touchée");
        $this->assertContains([null, 'ACTIVE', 1], $rows['main-or-else'], 'la liste principale en cours reste en cours');
    }

    public function testDetachingAListTypeKeepsEveryRowMealAndIngredientOfItsLists(): void
    {
        // Arrange
        $user = $this->savedUser();
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $list = new ShoppingListBuilder()->withOwner($user)->withListTypeId('type-1')->addRecipe($recipe)->addIngredient(new IngredientBuilder()->isEgg())->build();
        $this->persister->saveIngredient(new IngredientBuilder()->isEgg()->build());
        $this->shoppingListRepository->save($list);
        $this->entityManager->clear();
        $connection = $this->entityManager->getConnection();
        $before = [
            (int) $connection->fetchOne('SELECT COUNT(*) FROM meal'),
            (int) $connection->fetchOne('SELECT COUNT(*) FROM shopping_list_row'),
            (int) $connection->fetchOne('SELECT COUNT(*) FROM shopping_list_ingredient'),
        ];

        // Act
        $this->shoppingListRepository->detachFromListType($user->getId(), 'type-1');
        $this->entityManager->clear();

        // Assert : aucune ligne n'est déplacée, fusionnée ni perdue
        $this->assertGreaterThan(0, array_sum($before));
        $this->assertSame($before, [
            (int) $connection->fetchOne('SELECT COUNT(*) FROM meal'),
            (int) $connection->fetchOne('SELECT COUNT(*) FROM shopping_list_row'),
            (int) $connection->fetchOne('SELECT COUNT(*) FROM shopping_list_ingredient'),
        ]);
        $reloaded = $this->shoppingListRepository->findOneById($list->getId());
        $this->assertNull($reloaded->getListTypeId());
        RepasAssert::assertShoppingListRows($list->getRows(), $reloaded->getRows());
    }

    public function testFindsTheCurrentListOfEachTypeAndTheMainListApart(): void
    {
        // Given : la principale, la Boucherie et le Marché sont en cours ; une Boucherie en pause ; la liste d'un autre
        $user = $this->savedUser();
        $other = $this->savedUser();
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('main')->withOwner($user)->build());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('butcher')->withOwner($user)->withListTypeId('type-butcher')->build());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('market')->withOwner($user)->withListTypeId('type-market')->build());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('butcher-paused')->withOwner($user)->withListTypeId('type-butcher')->withStatus(ShoppingListStatus::PAUSED)->build());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('theirs')->withOwner($other)->withListTypeId('type-butcher')->build());

        // When / Then
        $this->assertSame('main', $this->shoppingListRepository->findOneActiveByOwnerAndType($user->getId(), null)?->getId());
        $this->assertSame('main', $this->shoppingListRepository->findOneActivateByOwner($user->getId())?->getId());
        $this->assertSame('butcher', $this->shoppingListRepository->findOneActiveByOwnerAndType($user->getId(), 'type-butcher')?->getId());
        $this->assertSame('market', $this->shoppingListRepository->findOneActiveByOwnerAndType($user->getId(), 'type-market')?->getId());
        $this->assertNull($this->shoppingListRepository->findOneActiveByOwnerAndType($user->getId(), 'type-fish'));
        $this->assertSame([true, true, true, false], [
            $this->shoppingListRepository->hasActiveShoppingList($user->getId()),
            $this->shoppingListRepository->hasActiveShoppingList($user->getId(), 'type-butcher'),
            $this->shoppingListRepository->hasActiveShoppingList($user->getId(), 'type-market'),
            $this->shoppingListRepository->hasActiveShoppingList($user->getId(), 'type-fish'),
        ]);
    }

    public function testTheMainListIsNotFoundWhenOnlyATypedListIsInProgress(): void
    {
        // Given
        $user = $this->savedUser();
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('butcher')->withOwner($user)->withListTypeId('type-butcher')->build());

        // When / Then
        $this->assertNull($this->shoppingListRepository->findOneActivateByOwner($user->getId()));
        $this->assertFalse($this->shoppingListRepository->hasActiveShoppingList($user->getId()));
    }

    public function testTheOldestListInProgressOfATypeWinsWhenTwoWereOpenedAtOnce(): void
    {
        // Given : deux ouvertures simultanées (ADR 0036 « Conséquences »)
        $user = $this->savedUser();
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('b-recent')->withOwner($user)->withListTypeId('type-butcher')->withCreatedAt(new \DateTimeImmutable('2026-10-20 10:00:00'))->build());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('a-old')->withOwner($user)->withListTypeId('type-butcher')->withCreatedAt(new \DateTimeImmutable('2026-10-10 10:00:00'))->build());

        // When / Then
        $this->assertSame('a-old', $this->shoppingListRepository->findOneActiveByOwnerAndType($user->getId(), 'type-butcher')?->getId());
    }

    public function testThePlacedIngredientsOfAMealRoundTripThroughTheDatabase(): void
    {
        // Arrange : un repas d'une recette éclatée entre plusieurs listes (ADR 0036, LST-005) se souvient des produits qu'il a posés dans sa liste
        $user = $this->savedUser();
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $list = new ShoppingListBuilder()->withOwner($user)->withListTypeId('type-1')->build();
        $list->addMeal('split-meal', $recipe, 4, 'group-1', ['lardon', 'parmesan', 'lardon']);
        $this->shoppingListRepository->save($list);
        $this->entityManager->clear();

        // Act
        $loaded = $this->shoppingListRepository->findOneById($list->getId());

        // Assert : lus en base, sans doublon, dans l'ordre où le repas les a posés
        $meal = $loaded->getMeals()->reset();
        $this->assertSame(['group-1', ['lardon', 'parmesan']], [$meal->getPlannedGroupId(), $meal->getPlacedIngredients()]);
        $this->assertSame('["lardon", "parmesan"]', $this->entityManager->getConnection()->fetchOne("SELECT placed_ingredients FROM meal WHERE id = 'split-meal'"));

        // Act : changer les personnes enregistre le repas sans rien perdre de sa répartition
        $meal->setServing(6);
        $this->shoppingListRepository->save($loaded);
        $this->entityManager->clear();

        // Assert
        $reloaded = $this->shoppingListRepository->findOneById($list->getId())->getMeals()->reset();
        $this->assertSame([6, 'group-1', ['lardon', 'parmesan']], [$reloaded->getServing(), $reloaded->getPlannedGroupId(), $reloaded->getPlacedIngredients()]);
    }

    public function testAMealPlannedAloneHasNoPlacedIngredients(): void
    {
        // Arrange
        $user = $this->savedUser();
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $list = new ShoppingListBuilder()->withOwner($user)->addRecipe($recipe)->build();
        $this->shoppingListRepository->save($list);
        $this->entityManager->clear();

        // Act
        $meal = $this->shoppingListRepository->findOneById($list->getId())->getMeals()->reset();

        // Assert : « toute la recette » reste vide en base
        $this->assertNull($meal->getPlacedIngredients());
        $this->assertNull($this->entityManager->getConnection()->fetchOne('SELECT placed_ingredients FROM meal'));
    }

    public function testTheOtherListsOfAPlannedGroupAreReadInOneQueryWhateverTheirStatus(): void
    {
        // Arrange : trois listes portent un repas du groupe (en cours, en pause, envoyée), une quatrième un repas d'un autre groupe
        $user = $this->savedUser();
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $lists = [];
        foreach (['a-main' => [null, ShoppingListStatus::ACTIVE, 'group-1'], 'b-butcher' => ['butcher', ShoppingListStatus::PAUSED, 'group-1'], 'c-market' => ['market', ShoppingListStatus::SENT, 'group-1'], 'd-other' => ['butcher', ShoppingListStatus::ACTIVE, 'group-2']] as $id => [$typeId, $status, $group]) {
            $builder = new ShoppingListBuilder()->withId($id)->withOwner($user)->withListTypeId($typeId)->withCreatedAt(new \DateTimeImmutable('2026-01-15T10:0' . ord($id[0]) % 10 . ':00+00:00'));
            if ($status === ShoppingListStatus::SENT) {
                $builder->addIngredient(new IngredientBuilder()->isEgg());
            }
            $list = $builder->build();
            $list->addMeal("meal-$id", $recipe, 4, $group);
            $list->setStatus($status);
            $this->shoppingListRepository->save($list);
            $lists[$id] = $list;
        }
        $this->entityManager->clear();
        $counter = static::getContainer()->get(\Repas\Tests\Helper\SqlBudget\QueryCounter::class);
        $counter->reset();

        // Act
        $others = $this->shoppingListRepository->findOtherListsOfPlannedGroup($user->getId(), 'group-1', 'a-main');
        $selectsOfTheGroup = array_sum(array_filter($counter->byTable(), static fn(int $count, string $key): bool => $key === 'SELECT shopping_list', ARRAY_FILTER_USE_BOTH));

        // Assert : les deux autres, la plus ancienne d'abord ; une seule requête pour trouver les listes
        $this->assertSame(['b-butcher', 'c-market'], array_values($others->map(static fn(ShoppingList $list): string => $list->getId())->toArray()));
        $this->assertSame(1, $selectsOfTheGroup);
        $this->assertSame([], $this->shoppingListRepository->findOtherListsOfPlannedGroup($user->getId(), 'group-3', 'a-main')->toArray());
    }

    public function testTheListInProgressThatPlansARecipeIsTheMainListFirstThenTheOldest(): void
    {
        // Arrange : la recette est planifiée dans une Boucherie récente, une Boucherie ancienne en pause, un Marché ancien en cours, et dans la
        // liste d'un autre compte
        $user = $this->savedUser();
        $other = $this->savedUser();
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $make = function (string $id, User $owner, ?string $typeId, string $date, ShoppingListStatus $status = ShoppingListStatus::ACTIVE) use ($recipe): void {
            $list = new ShoppingListBuilder()->withId($id)->withOwner($owner)->withListTypeId($typeId)->withCreatedAt(new \DateTimeImmutable($date))->build();
            $list->addMeal("meal-$id", $recipe, 4);
            $list->setStatus($status);
            $this->shoppingListRepository->save($list);
        };
        $make('butcher-new', $user, 'butcher', '2026-03-01T10:00:00+00:00');
        $make('butcher-old-paused', $user, 'butcher', '2026-01-01T10:00:00+00:00', ShoppingListStatus::PAUSED);
        $make('market-old', $user, 'market', '2026-02-01T10:00:00+00:00');
        // La liste d'un autre compte porte un repas de la même recette (hérité d'avant la règle « la recette est à son propriétaire »)
        $theirs = new ShoppingListBuilder()->withId('theirs')->withOwner($other)->withCreatedAt(new \DateTimeImmutable('2026-01-01T10:00:00+00:00'))->build();
        $this->shoppingListRepository->save($theirs);
        $this->entityManager->getConnection()->executeStatement("INSERT INTO meal (id, shopping_list, recipe, serving) VALUES ('meal-theirs', 'theirs', ?, 4)", [$recipe->getId()]);
        $this->entityManager->clear();

        // Act / Assert : sans liste principale, la plus ancienne des listes de type en cours
        $this->assertSame('market-old', $this->shoppingListRepository->findOneActiveWithRecipe($user->getId(), $recipe->getId())?->getId());

        // Arrange : la liste principale la planifie aussi, plus récente
        $make('main', $user, null, '2026-06-01T10:00:00+00:00');
        $this->entityManager->clear();

        // Act / Assert : la liste principale d'abord
        $this->assertSame('main', $this->shoppingListRepository->findOneActiveWithRecipe($user->getId(), $recipe->getId())?->getId());
        $this->assertNull($this->shoppingListRepository->findOneActiveWithRecipe($user->getId(), 'unknown-recipe'));
        $this->assertNull($this->shoppingListRepository->findOneActiveWithRecipe($this->savedUser()->getId(), $recipe->getId()));
    }

    public function testTheListInProgressThatHoldsALineOfAProductIsTheMainListFirstThenTheOldestAndNeverASentOne(): void
    {
        // Arrange
        $user = $this->savedUser();
        $make = function (string $id, ?string $typeId, string $date, ShoppingListStatus $status = ShoppingListStatus::ACTIVE) use ($user): void {
            $builder = new ShoppingListBuilder()->withId($id)->withOwner($user)->withListTypeId($typeId)->withCreatedAt(new \DateTimeImmutable($date))->addIngredient(new IngredientBuilder()->isEgg());
            $this->shoppingListRepository->save($builder->withStatus($status)->build());
        };
        $make('sent-main', null, '2026-01-01T10:00:00+00:00', ShoppingListStatus::SENT);
        $make('butcher-new', 'butcher', '2026-03-01T10:00:00+00:00');
        $make('market-old', 'market', '2026-02-01T10:00:00+00:00');
        $this->entityManager->clear();

        // Act / Assert : la liste envoyée n'est jamais rendue ; la plus ancienne des listes de type
        $this->assertSame('market-old', $this->shoppingListRepository->findOneActiveWithRow($user->getId(), 'oeuf')?->getId());

        // Arrange
        $make('main', null, '2026-06-01T10:00:00+00:00');
        $this->entityManager->clear();

        // Act / Assert
        $this->assertSame('main', $this->shoppingListRepository->findOneActiveWithRow($user->getId(), 'oeuf')?->getId());
        $this->assertNull($this->shoppingListRepository->findOneActiveWithRow($user->getId(), 'lardon'));
    }
}
