<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\RemoveShoppingListType\RemoveShoppingListTypeCommand;
use Repas\Repas\Application\Command\RemoveShoppingListType\RemoveShoppingListTypeHandler;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Domain\Model\ShoppingListType;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListTypeInMemoryRepository;

class RemoveShoppingListTypeHandlerTest extends TestCase
{
    private ShoppingListTypeInMemoryRepository $listTypeRepository;
    private ShoppingListInMemoryRepository $shoppingListRepository;
    private IngredientInMemoryRepository $ingredientRepository;
    private RemoveShoppingListTypeHandler $handler;

    protected function setUp(): void
    {
        $owner = new UserBuilder()->withId('user-1')->build();
        $other = new UserBuilder()->withId('user-2')->build();

        $this->listTypeRepository = new ShoppingListTypeInMemoryRepository([
            self::type('butcher', 'user-1', 'Boucherie', ['viande', 'fromage']),
            self::type('market', 'user-1', 'Marché', ['divers']),
            self::type('other', 'user-2', 'Boucherie', ['viande']),
        ]);
        $this->shoppingListRepository = new ShoppingListInMemoryRepository([
            new ShoppingListBuilder()->withId('butcher-active')->withOwner($owner)->withListTypeId('butcher')->withName('Boucherie')->addIngredient(new IngredientBuilder()->isButter())->build(),
            new ShoppingListBuilder()->withId('butcher-sent')->withOwner($owner)->withListTypeId('butcher')->withStatus(ShoppingListStatus::SENT)->build(),
            new ShoppingListBuilder()->withId('butcher-paused')->withOwner($owner)->withListTypeId('butcher')->withStatus(ShoppingListStatus::PAUSED)->build(),
            new ShoppingListBuilder()->withId('butcher-completed')->withOwner($owner)->withListTypeId('butcher')->withStatus(ShoppingListStatus::COMPLETED)->build(),
            new ShoppingListBuilder()->withId('market-active')->withOwner($owner)->withListTypeId('market')->build(),
            new ShoppingListBuilder()->withId('main-active')->withOwner($owner)->build(),
            new ShoppingListBuilder()->withId('other-active')->withOwner($other)->withListTypeId('other')->build(),
        ]);
        $this->ingredientRepository = new IngredientInMemoryRepository([
            new IngredientBuilder()->isButter()->withSlug('beurre-1')->withCreator($owner)->withListDestination('butcher')->build(),
            new IngredientBuilder()->isFlour()->withSlug('farine-1')->withCreator($owner)->withListDestination('market')->build(),
            new IngredientBuilder()->isEgg()->withSlug('oeuf-1')->withCreator($owner)->withListDestination('main')->build(),
            new IngredientBuilder()->isPasta()->withSlug('pate-1')->withCreator($owner)->build(),
            new IngredientBuilder()->isButter()->withSlug('beurre-2')->withCreator($other)->withListDestination('other')->build(),
        ]);
        $this->handler = new RemoveShoppingListTypeHandler($this->listTypeRepository, $this->shoppingListRepository, $this->ingredientRepository);
    }

    public function testTheTypeAndItsDepartmentsDisappearAndTheOthersStay(): void
    {
        // When
        ($this->handler)(new RemoveShoppingListTypeCommand('butcher', 'user-1'));

        // Then
        $this->assertSame(['market', 'other'], array_map(static fn(ShoppingListType $type): string => $type->getId(), $this->listTypeRepository->all()));
        $this->assertSame(['divers'], $this->listTypeRepository->findOneById('market')->getDepartmentSlugs());
        $this->assertSame(['viande'], $this->listTypeRepository->findOneById('other')->getDepartmentSlugs());
    }

    public function testItsListsLoseTheirTypeAndTheCurrentOneIsPausedWhileTheOthersKeepTheirStatus(): void
    {
        // When
        ($this->handler)(new RemoveShoppingListTypeCommand('butcher', 'user-1'));

        // Then : plus de type, le statut ne change que pour la liste en cours
        $statuses = [];
        foreach (['butcher-active', 'butcher-sent', 'butcher-paused', 'butcher-completed'] as $id) {
            $list = $this->shoppingListRepository->findOneById($id);
            $this->assertNull($list->getListTypeId(), $id);
            $statuses[$id] = $list->getStatus();
        }
        $this->assertSame(
            ['butcher-active' => ShoppingListStatus::PAUSED, 'butcher-sent' => ShoppingListStatus::SENT, 'butcher-paused' => ShoppingListStatus::PAUSED, 'butcher-completed' => ShoppingListStatus::COMPLETED],
            $statuses,
        );
    }

    public function testNoLineIsLostOrMoved(): void
    {
        // When
        ($this->handler)(new RemoveShoppingListTypeCommand('butcher', 'user-1'));

        // Then
        $list = $this->shoppingListRepository->findOneById('butcher-active');
        $this->assertSame('Boucherie', $list->getName());
        $this->assertSame(['beurre'], array_values($list->getRows()->map(fn($row) => $row->getIngredientSlug())->toArray()));
    }

    public function testTheListsOfTheOtherTypesAndTheMainListAreNotTouched(): void
    {
        // When
        ($this->handler)(new RemoveShoppingListTypeCommand('butcher', 'user-1'));

        // Then
        $market = $this->shoppingListRepository->findOneById('market-active');
        $this->assertSame(['market', ShoppingListStatus::ACTIVE], [$market->getListTypeId(), $market->getStatus()]);
        $main = $this->shoppingListRepository->findOneById('main-active');
        $this->assertSame([null, ShoppingListStatus::ACTIVE], [$main->getListTypeId(), $main->getStatus()]);
        $other = $this->shoppingListRepository->findOneById('other-active');
        $this->assertSame(['other', ShoppingListStatus::ACTIVE], [$other->getListTypeId(), $other->getStatus()]);
    }

    public function testTheProductsThatNamedTheTypeAreLikeTheirDepartmentAgainAndTheOthersKeepTheirChoice(): void
    {
        // When
        ($this->handler)(new RemoveShoppingListTypeCommand('butcher', 'user-1'));

        // Then : ni « liste principale » (`main`), ni un autre type, ni le choix d'un autre compte ne bougent
        $this->assertSame(
            [null, 'market', 'main', null, 'other'],
            [$this->destinationOf('beurre-1'), $this->destinationOf('farine-1'), $this->destinationOf('oeuf-1'), $this->destinationOf('pate-1'), $this->destinationOf('beurre-2')],
        );
    }

    public function testTheTypeOfAnotherUserIsUnknownAndNothingIsWritten(): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListTypeException::notFound());

        // When
        try {
            ($this->handler)(new RemoveShoppingListTypeCommand('butcher', 'user-2'));
        } finally {
            $this->assertCount(3, $this->listTypeRepository->all());
            $this->assertSame('butcher', $this->shoppingListRepository->findOneById('butcher-active')->getListTypeId());
            $this->assertSame('butcher', $this->destinationOf('beurre-1'));
        }
    }

    public function testAnUnknownTypeIsNotFound(): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListTypeException::notFound());

        // When
        ($this->handler)(new RemoveShoppingListTypeCommand('inconnu', 'user-1'));
    }

    private function destinationOf(string $slug): ?string
    {
        return $this->ingredientRepository->findOneBySlug($slug)->getListDestination();
    }

    /**
     * @param list<string> $departments
     */
    private static function type(string $id, string $owner, string $name, array $departments): ShoppingListType
    {
        return ShoppingListType::load(['id' => $id, 'owner_id' => $owner, 'name' => $name, 'slugged_name' => mb_strtolower(str_replace('é', 'e', $name)), 'created_at' => new DateTimeImmutable('2026-10-31 10:00:00'), 'department_slugs' => $departments]);
    }
}
