<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Application\Loader\ShoppingListContentLoader;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Interface\UserRepository;

class ShoppingListContentPostgreSQLLoaderTest extends DatabaseTestCase
{
    private ShoppingListRepository $shoppingListRepository;
    private ShoppingListContentLoader $loader;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shoppingListRepository = static::getContainer()->get(ShoppingListRepository::class);
        $this->loader = static::getContainer()->get(ShoppingListContentLoader::class);
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
        $salt = new IngredientBuilder()->isMilk()->withSlug('sel')->withName('sel')->withDepartment(new DepartmentBuilder()->isConserve())->withDefaultPurchaseUnit(new UnitBuilder()->isSoupSpoon())->build();
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
        $this->assertSame(['list-1', $owner->getId(), 'ACTIVE'], [$result['id'], $result['ownerId'], $result['status']]);
        $this->assertSame(['lait', 'oeuf', 'pate', 'sel'], array_column($result['lines'], 'ingredientSlug'));
        $this->assertEquals(
            [
                self::line('lait', 'Lait', 1.0, 'litre', 'litre', 'l', 'VOLUME', 1000.0, 'divers'),
                self::line('oeuf', 'Œuf', 1.0, 'unite', 'unité', '', 'COUNT', 1.0, 'divers'),
                self::line('pate', 'Pate', 500.0, 'gramme', 'gramme', 'g', 'WEIGHT', 1.0, 'cereale'),
                self::line('sel', 'sel', 1.0, 'cuillere-a-soupe', 'cuillère à soupe', 'cuillère à soupe', null, null, 'conserve'),
            ],
            $result['lines'],
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
        $this->assertSame(['SENT', 1], [$result['status'], $result['version']]);
        $this->assertCount(1, $result['lines']);
    }

    public function testGetLeavesOutTheLinesThatAreAlreadyChecked(): void
    {
        // Given (DRV-745) : lait, œuf et pâte, l'œuf déjà coché
        $owner = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($owner);
        $builder = new ShoppingListBuilder()->withId('list-checked')->withOwner($owner);
        foreach ([new IngredientBuilder()->isMilk()->build(), new IngredientBuilder()->isEgg()->build(), new IngredientBuilder()->isPasta()->build()] as $ingredient) {
            $this->persister->saveIngredient($ingredient);
            $builder->addIngredient($ingredient);
        }
        $shoppingList = $builder->build();
        foreach ($shoppingList->getRows() as $row) {
            if ($row->getIngredientSlug() === 'oeuf') {
                $row->tick();
            }
        }
        $this->shoppingListRepository->save($shoppingList);

        // When
        $result = $this->loader->get('list-checked');

        // Then : la ligne cochée ne part pas, les autres restent triées par slug
        $this->assertSame(['lait', 'pate'], array_column($result['lines'], 'ingredientSlug'));
    }

    public function testGetReturnsNoLineWhenEveryLineIsChecked(): void
    {
        // Given
        $owner = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($owner);
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->persister->saveIngredient($egg);
        $shoppingList = new ShoppingListBuilder()->withId('list-all-checked')->withOwner($owner)->addIngredient($egg)->build();
        foreach ($shoppingList->getRows() as $row) {
            $row->tick();
        }
        $this->shoppingListRepository->save($shoppingList);

        // When
        $result = $this->loader->get('list-all-checked');

        // Then
        $this->assertSame([], $result['lines']);
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
        $this->assertSame(['id' => 'list-empty', 'ownerId' => $owner->getId(), 'status' => 'ACTIVE', 'version' => 1, 'lines' => []], $result);
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
        $this->assertSame(['oeuf'], array_column($result['lines'], 'ingredientSlug'));
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

    /**
     * @return array{ingredientSlug: string, ingredientName: string, quantity: float, unitSlug: string, unitName: string, unitSymbol: string, unitDimension: ?string, unitBaseFactor: ?float, departmentSlug: ?string}
     */
    private static function line(string $ingredientSlug, string $ingredientName, float $quantity, string $unitSlug, string $unitName, string $unitSymbol, ?string $unitDimension, ?float $unitBaseFactor, ?string $departmentSlug): array
    {
        return compact('ingredientSlug', 'ingredientName', 'quantity', 'unitSlug', 'unitName', 'unitSymbol', 'unitDimension', 'unitBaseFactor', 'departmentSlug');
    }
}
