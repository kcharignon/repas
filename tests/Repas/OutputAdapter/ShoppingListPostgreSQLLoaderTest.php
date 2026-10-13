<?php

namespace Repas\Tests\Repas\OutputAdapter;


use DateTimeImmutable;
use Repas\Repas\Application\Loader\ShoppingListLoader;
use Repas\Repas\Application\Query\ListShoppingLists\ShoppingListDTO;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;

class ShoppingListPostgreSQLLoaderTest extends DatabaseTestCase
{
    private ShoppingListRepository $shoppingListRepository;
    private UserRepository $userRepository;
    private ShoppingListLoader $shoppingListLoader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shoppingListRepository = static::getContainer()->get(ShoppingListRepository::class);
        $this->userRepository = static::getContainer()->get(UserRepository::class);
        $this->shoppingListLoader = static::getContainer()->get(ShoppingListLoader::class);
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
