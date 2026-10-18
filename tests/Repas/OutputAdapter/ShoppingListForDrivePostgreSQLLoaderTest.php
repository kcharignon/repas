<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Application\Dto\ShoppingListForDrive;
use Repas\Shared\Application\Dto\ShoppingListLineForDrive;
use Repas\Shared\Application\Interface\ShoppingListForDriveLoader;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Interface\UserRepository;

class ShoppingListForDrivePostgreSQLLoaderTest extends DatabaseTestCase
{
    private ShoppingListRepository $shoppingListRepository;
    private ShoppingListForDriveLoader $loader;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shoppingListRepository = static::getContainer()->get(ShoppingListRepository::class);
        $this->loader = static::getContainer()->get(ShoppingListForDriveLoader::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testGetReturnsTheListWithItsLinesSortedByIngredientSlug(): void
    {
        // Given : lait (litre), œuf (unité), pâte (gramme, quantité 499 + 1), sel (cuillère : sans dimension)
        $owner = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($owner);
        $milk = new IngredientBuilder()->isMilk()->build();
        $egg = new IngredientBuilder()->isEgg()->build();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $salt = new IngredientBuilder()->isMilk()->withSlug('sel')->withName('sel')->withDefaultPurchaseUnit(new UnitBuilder()->isSoupSpoon())->build();
        $builder = new ShoppingListBuilder()->withId('list-1')->withOwner($owner)->withVersion(3);
        foreach ([$salt, $pasta, $egg, $milk] as $ingredient) {
            $this->persister->saveIngredient($ingredient);
            $builder->addIngredient($ingredient);
        }
        $shoppingList = $builder->build();
        $shoppingList->addRow($pasta->getSlug(), $pasta->getDefaultPurchaseUnit()->getSlug(), 499);
        $this->shoppingListRepository->save($shoppingList);

        // When
        $result = $this->loader->get('list-1');

        // Then
        $this->assertSame(['list-1', $owner->getId(), 'ACTIVE'], [$result->id, $result->ownerId, $result->status]);
        $this->assertSame(['lait', 'oeuf', 'pate', 'sel'], array_map(static fn(ShoppingListLineForDrive $line): string => $line->ingredientSlug, $result->lines));
        $this->assertEquals(
            [
                new ShoppingListLineForDrive('lait', 'lait', 1.0, 'litre', 'l', 'VOLUME', 1000.0),
                new ShoppingListLineForDrive('oeuf', 'œuf', 1.0, 'unite', '', 'COUNT', 1.0),
                new ShoppingListLineForDrive('pate', 'pate', 500.0, 'gramme', 'g', 'WEIGHT', 1.0),
                new ShoppingListLineForDrive('sel', 'sel', 1.0, 'cuillere-a-soupe', 'cuillère à soupe', null, null),
            ],
            $result->lines,
        );
    }

    public function testGetReturnsTheStatusAndTheVersionOfASentList(): void
    {
        // Given
        $owner = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($owner);
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->persister->saveIngredient($egg);
        $this->shoppingListRepository->save(
            new ShoppingListBuilder()->withId('list-sent')->withOwner($owner)->addIngredient($egg)->withStatus(ShoppingListStatus::SENT)->build()
        );

        // When
        $result = $this->loader->get('list-sent');

        // Then
        $this->assertSame(['SENT', 1], [$result->status, $result->version]);
        $this->assertCount(1, $result->lines);
    }

    public function testGetReturnsNoLineForAnEmptyList(): void
    {
        // Given
        $owner = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($owner);
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withId('list-empty')->withOwner($owner)->build());

        // When
        $result = $this->loader->get('list-empty');

        // Then
        $this->assertEquals(new ShoppingListForDrive('list-empty', $owner->getId(), 'ACTIVE', 1, []), $result);
    }

    public function testGetIgnoresALineWhoseIngredientDisappeared(): void
    {
        // Given : l'ingrédient du pain n'est jamais enregistré (aucune clé étrangère)
        $owner = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($owner);
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->persister->saveIngredient($egg);
        $this->shoppingListRepository->save(
            new ShoppingListBuilder()->withId('list-1')->withOwner($owner)->addIngredient($egg)->addIngredient(new IngredientBuilder()->isBread())->build()
        );

        // When
        $result = $this->loader->get('list-1');

        // Then
        $this->assertSame(['oeuf'], array_map(static fn(ShoppingListLineForDrive $line): string => $line->ingredientSlug, $result->lines));
    }

    public function testGetFailsForAnUnknownList(): void
    {
        $this->expectExceptionObject(ShoppingListException::shoppingListNotFound('inconnue'));

        $this->loader->get('inconnue');
    }

    public function testGetCostsTwoQueries(): void
    {
        // Given
        $owner = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($owner);
        $egg = new IngredientBuilder()->isEgg()->build();
        $milk = new IngredientBuilder()->isMilk()->build();
        foreach ([$egg, $milk] as $ingredient) {
            $this->persister->saveIngredient($ingredient);
        }
        $this->shoppingListRepository->save(
            new ShoppingListBuilder()->withId('list-1')->withOwner($owner)->addIngredient($egg)->addIngredient($milk)->build()
        );
        static::getContainer()->get('doctrine')->getManager()->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->loader->get('list-1');

        // Then
        $this->assertSame(['SELECT shopping_list' => 1, 'SELECT shopping_list_row' => 1], $counter->byTable());
    }
}
