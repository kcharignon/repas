<?php

namespace Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\RemoveIngredientToShoppingList\RemoveIngredientToShoppingListCommand;
use Repas\Repas\Application\Command\RemoveIngredientToShoppingList\RemoveIngredientToShoppingListHandler;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\ShoppingListIngredient;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\IngredientSlugResolverFactory;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\User\Domain\Model\User;

class RemoveIngredientToShoppingListHandlerTest extends TestCase
{
    private RemoveIngredientToShoppingListHandler $handler;
    private ShoppingListRepository $shoppingListRepository;
    private IngredientRepository $ingredientRepository;
    private IngredientAliasInMemoryRepository $aliasRepository;

    private User $user;
    private Ingredient $ingredient;

    protected function setUp(): void
    {
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();
        $this->ingredientRepository = new IngredientInMemoryRepository();
        $this->aliasRepository = new IngredientAliasInMemoryRepository();
        $this->handler = new RemoveIngredientToShoppingListHandler(
            $this->shoppingListRepository,
            $this->ingredientRepository,
            IngredientSlugResolverFactory::create($this->ingredientRepository, $this->aliasRepository),
        );

        $this->user = new UserBuilder()->withId('user-id')->build();
        $this->ingredient = new IngredientBuilder()->isPasta()->build();
        $this->ingredientRepository->save($this->ingredient);
    }

    public function testHandleSuccessfullyRemoveIngredientToShoppingListHandlerRemoveIngredient(): void
    {
        // Arrange
        $shoppingList = new ShoppingListBuilder()
            ->withId('shopping-list-id')
            ->withOwner($this->user)
            ->addIngredient($this->ingredient)
            ->build();
        $this->shoppingListRepository->save($shoppingList);
        $command = new RemoveIngredientToShoppingListCommand(
            'user-id',
            'pate',
        );


        // Act
        ($this->handler)($command);

        // Assert
        $shoppingList = $this->shoppingListRepository->findOneById('shopping-list-id');
        $this->assertEmpty($shoppingList->getIngredients());
        $this->assertEmpty($shoppingList->getRows());
    }

    public function testHandleSuccessfullyRemoveIngredientToShoppingListHandlerReduceQuantity(): void
    {
        // Arrange
        $shoppingList = new ShoppingListBuilder()
            ->withId('shopping-list-id')
            ->withOwner($this->user)
            ->addIngredient($this->ingredient)
            ->addIngredient($this->ingredient)
            ->addIngredient($this->ingredient)
            ->build();
        $this->shoppingListRepository->save($shoppingList);

        $command = new RemoveIngredientToShoppingListCommand(
            'user-id',
            'pate',
        );

        // Act
        ($this->handler)($command);

        // Assert
        $shoppingList = $this->shoppingListRepository->findOneById('shopping-list-id');
        $this->assertCount(1, $shoppingList->getIngredients());
        $this->assertCount(1, $shoppingList->getRows());
        $this->assertEquals(2, $shoppingList->getIngredients()->find(fn(ShoppingListIngredient $spi) => $spi->hasIngredient($this->ingredient->getSlug()))->getQuantity());
        $this->assertEquals(2, $shoppingList->getRows()->find(fn(ShoppingListRow $spr) => $spr->getIngredientSlug() === $this->ingredient->getSlug())->getQuantity());
    }

    public function testFailedHandleRemoveIngredientToShoppingListShoppingListNotFound(): void
    {
        // Arrange
        $command = new RemoveIngredientToShoppingListCommand(
            'user-id',
            'pate',
        );

        // Assert
        $this->expectExceptionObject(ShoppingListException::activeShoppingListNotFound());

        // Act
        ($this->handler)($command);
    }


    public function testRemovesTheCopyOfACommonTheOwnerAdoptedWhenAnOldSlugIsSent(): void
    {
        // Arrange : la liste du propriétaire porte la copie des pâtes (il les a adoptées), son client envoie encore l'ancien slug
        $copy = new IngredientBuilder()->isPasta()->withCreator($this->user, true)->withAdoptedFrom('pate')->build();
        $this->ingredientRepository->save($copy);
        $this->aliasRepository->save(IngredientAlias::create('user-id', 'pate', $copy->getSlug(), AliasKind::ADOPTION, 'migration-2026-10-21', new DateTimeImmutable()));
        $shoppingList = new ShoppingListBuilder()->withId('shopping-list-id')->withOwner($this->user)->addIngredient($copy)->addIngredient($copy)->build();
        $this->shoppingListRepository->save($shoppingList);

        // Act
        ($this->handler)(new RemoveIngredientToShoppingListCommand('user-id', 'pate'));

        // Assert : une unité de moins sur la copie
        $shoppingList = $this->shoppingListRepository->findOneById('shopping-list-id');
        $this->assertEquals(1, $shoppingList->getRows()->find(fn(ShoppingListRow $row) => $row->getIngredientSlug() === $copy->getSlug())->getQuantity());
    }

    public function testNeverAdoptsACommonJustToRemoveIt(): void
    {
        // Arrange : un adoptant (il a adopté le lait) retire de sa liste les pâtes, qu'il n'a jamais adoptées
        $milk = new IngredientBuilder()->isMilk()->build();
        $milkCopy = new IngredientBuilder()->isMilk()->withCreator($this->user, true)->withAdoptedFrom($milk->getSlug())->build();
        $this->ingredientRepository->save($milk);
        $this->ingredientRepository->save($milkCopy);
        $this->aliasRepository->save(IngredientAlias::create('user-id', $milk->getSlug(), $milkCopy->getSlug(), AliasKind::ADOPTION, 'migration-2026-10-21', new DateTimeImmutable()));
        $shoppingList = new ShoppingListBuilder()->withId('shopping-list-id')->withOwner($this->user)->addIngredient($this->ingredient)->addIngredient($this->ingredient)->build();
        $this->shoppingListRepository->save($shoppingList);

        // Act
        ($this->handler)(new RemoveIngredientToShoppingListCommand('user-id', 'pate'));

        // Assert : aucune adoption, la ligne de la liste (qui portait le commun) est celle qui diminue
        $this->assertCount(1, $this->aliasRepository->all());
        $this->assertFalse($this->ingredientRepository->exists('pate' . 'user-id'));
        $this->assertEquals(1, $this->shoppingListRepository->findOneById('shopping-list-id')->getRows()->find(fn(ShoppingListRow $row) => $row->getIngredientSlug() === 'pate')->getQuantity());
    }

    public function testWithoutAnyAliasTheSharedCommonIsRemovedAsBefore(): void
    {
        // Arrange
        $shoppingList = new ShoppingListBuilder()->withId('shopping-list-id')->withOwner($this->user)->addIngredient($this->ingredient)->addIngredient($this->ingredient)->build();
        $this->shoppingListRepository->save($shoppingList);

        // Act
        ($this->handler)(new RemoveIngredientToShoppingListCommand('user-id', 'pate'));

        // Assert
        $this->assertEquals(1, $this->shoppingListRepository->findOneById('shopping-list-id')->getRows()->find(fn(ShoppingListRow $row) => $row->getIngredientSlug() === 'pate')->getQuantity());
        $this->assertSame([], $this->aliasRepository->all());
    }

    public function testRefusesAnOutdatedVersionAndRemovesNothing(): void
    {
        // Arrange : la liste est à la version 1, le client croit à la 2
        $shoppingList = new ShoppingListBuilder()
            ->withId('shopping-list-id')
            ->withOwner($this->user)
            ->addIngredient($this->ingredient)
            ->build();
        $this->shoppingListRepository->save($shoppingList);
        $command = new RemoveIngredientToShoppingListCommand('user-id', 'pate', expectedVersion: 2);

        // Assert
        $this->expectExceptionObject(ShoppingListException::outdated('shopping-list-id'));

        // Act
        try {
            ($this->handler)($command);
        } finally {
            $this->assertCount(1, $shoppingList->getIngredients());
        }
    }

    public function testDoesNotTouchASentList(): void
    {
        // Arrange : le propriétaire n'a qu'une liste envoyée au drive (ADR 0023)
        $sent = new ShoppingListBuilder()
            ->withId('shopping-list-id')
            ->withOwner($this->user)
            ->addIngredient($this->ingredient)
            ->withStatus(ShoppingListStatus::SENT)
            ->build();
        $this->shoppingListRepository->save($sent);

        // Assert
        $this->expectExceptionObject(ShoppingListException::activeShoppingListNotFound());

        // Act
        try {
            ($this->handler)(new RemoveIngredientToShoppingListCommand('user-id', 'pate'));
        } finally {
            $this->assertEquals(1, $this->shoppingListRepository->findOneById('shopping-list-id')->getIngredients()->reset()->getQuantity());
        }
    }
}
