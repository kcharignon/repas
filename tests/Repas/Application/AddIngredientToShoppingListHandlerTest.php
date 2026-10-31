<?php

namespace Repas\Tests\Repas\Application;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\AddIngredientToShoppingList\AddIngredientToShoppingListCommand;
use Repas\Repas\Application\Command\AddIngredientToShoppingList\AddIngredientToShoppingListHandler;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\ShoppingListIngredient;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\TypedLists;

class AddIngredientToShoppingListHandlerTest extends TestCase
{
    private readonly AddIngredientToShoppingListHandler $handler;
    private readonly IngredientRepository $ingredientRepository;
    private readonly IngredientAliasInMemoryRepository $aliasRepository;
    private readonly ShoppingListRepository $shoppingListRepository;

    protected function setUp(): void
    {

        $this->ingredientRepository = new IngredientInMemoryRepository();
        $this->aliasRepository = new IngredientAliasInMemoryRepository();
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();

        $typedLists = new TypedLists($this->shoppingListRepository, $this->ingredientRepository);
        $this->handler =  new AddIngredientToShoppingListHandler(
            shoppingListRepository: $this->shoppingListRepository,
            slugResolver: new IngredientSlugResolver($this->aliasRepository),
            listRoutingRepository: $typedLists->routing,
            activeListForType: $typedLists->activeListForType,
        );
    }

    public function testHandleSuccessfullyAddsIngredientToShoppingList(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($user)->build();
        $this->ingredientRepository->save($egg);
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->build();
        $this->shoppingListRepository->save($shoppingList);

        $command = new AddIngredientToShoppingListCommand($user->getId(), $egg->getSlug());

        // Act
        ($this->handler)($command);

        //Assert
        $this->assertCount(1, $shoppingList->getIngredients());
        $this->assertCount(1, $shoppingList->getRows());
        $this->assertNotNull($shoppingList->getIngredients()->find(fn(ShoppingListIngredient $sli) => $sli->hasIngredient($egg->getSlug())));
        $this->assertEquals(1, $shoppingList->getIngredients()->find(fn(ShoppingListIngredient $sli) => $sli->hasIngredient($egg->getSlug()))?->getQuantity());
    }

    public function testHandleAddsIngredientToShoppingListWithMissingShoppingList(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($user)->build();
        $this->ingredientRepository->save($egg);

        $command = new AddIngredientToShoppingListCommand($user->getId(), $egg->getSlug());

        // Assert
        $this->expectExceptionObject(ShoppingListException::activeShoppingListNotFound());

        // Act
        ($this->handler)($command);
    }

    public function testHandleAddsACustomIngredientOfTheOwner(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $butter = new IngredientBuilder()->isButter()->withCreator($user, true)->build();
        $this->ingredientRepository->save($butter);
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->build();
        $this->shoppingListRepository->save($shoppingList);

        // Act
        ($this->handler)(new AddIngredientToShoppingListCommand($user->getId(), $butter->getSlug()));

        // Assert
        $this->assertCount(1, $shoppingList->getRows());
    }

    public function testHandleRefusesTheCustomIngredientOfAnotherUserAsUnknown(): void
    {
        // Arrange : l'ingrédient d'un autre n'est pas à l'utilisateur, et il n'y a plus de commun
        $user = new UserBuilder()->build();
        $butter = new IngredientBuilder()->isButter()->withCreator(new UserBuilder()->build(), true)->build();
        $this->ingredientRepository->save($butter);
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->build();
        $this->shoppingListRepository->save($shoppingList);

        // Act
        try {
            ($this->handler)(new AddIngredientToShoppingListCommand($user->getId(), $butter->getSlug()));
            $this->fail("L'ingrédient d'un autre utilisateur ne se pose pas dans la liste.");
        } catch (IngredientException $exception) {
            // Assert
            $this->assertEquals(IngredientException::notFound($butter->getSlug()), $exception);
            $this->assertCount(0, $shoppingList->getRows());
            $this->assertCount(0, $shoppingList->getIngredients());
        }
    }

    public function testAddsTheCopyOfAnOldCommonTheOwnerReceived(): void
    {
        // Arrange : le propriétaire a reçu une copie de l'œuf du catalogue ; son client envoie encore l'ancien slug (liste en cache)
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator(new UserBuilder()->withId('owner-id')->build())->build();
        $copy = new IngredientBuilder()->isEgg()->withCreator($user, true)->withAdoptedFrom($egg->getSlug())->build();
        $this->ingredientRepository->save($egg);
        $this->ingredientRepository->save($copy);
        $this->aliasRepository->save(IngredientAlias::create($user->getId(), $egg->getSlug(), $copy->getSlug(), AliasKind::ADOPTION, 'migration-2026-10-21', new DateTimeImmutable()));
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->build();
        $this->shoppingListRepository->save($shoppingList);

        // Act
        ($this->handler)(new AddIngredientToShoppingListCommand($user->getId(), $egg->getSlug()));

        // Assert : la ligne et l'ingrédient de la liste portent la copie, jamais le commun masqué
        $this->assertNotNull($shoppingList->getIngredients()->find(fn(ShoppingListIngredient $sli) => $sli->hasIngredient($copy->getSlug())));
        $this->assertNull($shoppingList->getIngredients()->find(fn(ShoppingListIngredient $sli) => $sli->hasIngredient($egg->getSlug())));
        $this->assertSame([$copy->getSlug()], array_values(array_map(static fn($row) => $row->getIngredientSlug(), $shoppingList->getRows()->toArray())));
    }

    public function testAnOldSlugWithoutAnAliasForThisUserIsUnknownAndNothingIsAdopted(): void
    {
        // Arrange : le propriétaire a reçu une copie du beurre, pas de l'œuf du catalogue ; il n'y a plus d'adoption à la première utilisation
        $user = new UserBuilder()->build();
        $owner = new UserBuilder()->withId('owner-id')->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($owner)->build();
        $butter = new IngredientBuilder()->isButter()->withCreator($owner)->build();
        $butterCopy = new IngredientBuilder()->isButter()->withCreator($user, true)->withAdoptedFrom($butter->getSlug())->build();
        foreach ([$egg, $butter, $butterCopy] as $ingredient) {
            $this->ingredientRepository->save($ingredient);
        }
        $this->aliasRepository->save(IngredientAlias::create($user->getId(), $butter->getSlug(), $butterCopy->getSlug(), AliasKind::ADOPTION, 'activation', new DateTimeImmutable()));
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->build();
        $this->shoppingListRepository->save($shoppingList);

        // Act
        try {
            ($this->handler)(new AddIngredientToShoppingListCommand($user->getId(), $egg->getSlug()));
            $this->fail("L'ingrédient du catalogue n'est pas à l'utilisateur : rien ne l'adopte à sa place.");
        } catch (IngredientException $exception) {
            // Assert : ingrédient inconnu, aucune copie, aucun alias de plus, la liste est intacte
            $this->assertEquals(IngredientException::notFound($egg->getSlug()), $exception);
            $this->assertCount(3, $this->ingredientRepository->findAll());
            $this->assertCount(1, $this->aliasRepository->all());
            $this->assertCount(0, $shoppingList->getRows());
        }
    }

    public function testAnAdministratorIsAnOrdinaryUserAndDoesNotAddTheIngredientOfAnother(): void
    {
        // Arrange : l'administrateur (ni propriétaire ni détenteur de l'œuf) n'a aucun passe-droit
        $admin = new UserBuilder()->isAdmin()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator(new UserBuilder()->withId('owner-id')->build())->build();
        $this->ingredientRepository->save($egg);
        $shoppingList = new ShoppingListBuilder()->withOwner($admin)->build();
        $this->shoppingListRepository->save($shoppingList);

        // Assert
        $this->expectExceptionObject(IngredientException::notFound($egg->getSlug()));

        // Act
        ($this->handler)(new AddIngredientToShoppingListCommand($admin->getId(), $egg->getSlug()));
    }

    public function testHandleAddsIngredientToShoppingListWithUnknownIngredient(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->build();
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->build();
        $this->shoppingListRepository->save($shoppingList);

        $command = new AddIngredientToShoppingListCommand($user->getId(), $egg->getSlug());

        // Assert
        $this->expectExceptionObject(IngredientException::notFound($egg->getSlug()));

        // Act
        ($this->handler)($command);
    }

    public function testRefusesAnOutdatedVersionAndAddsNothing(): void
    {
        // Arrange : la liste est à la version 1, le client croit à la 2
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($user)->build();
        $this->ingredientRepository->save($egg);
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->build();
        $this->shoppingListRepository->save($shoppingList);
        $command = new AddIngredientToShoppingListCommand($user->getId(), $egg->getSlug(), expectedVersion: 2);

        // Assert
        $this->expectExceptionObject(ShoppingListException::outdated($shoppingList->getId()));

        // Act
        try {
            ($this->handler)($command);
        } finally {
            $this->assertCount(0, $shoppingList->getIngredients());
        }
    }

    public function testDoesNotTouchASentList(): void
    {
        // Arrange : le propriétaire n'a qu'une liste envoyée au drive (ADR 0023)
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($user)->build();
        $this->ingredientRepository->save($egg);
        $sent = new ShoppingListBuilder()->withOwner($user)->addIngredient(new IngredientBuilder()->isEgg())->withStatus(ShoppingListStatus::SENT)->build();
        $this->shoppingListRepository->save($sent);

        // Assert
        $this->expectExceptionObject(ShoppingListException::activeShoppingListNotFound());

        // Act
        try {
            ($this->handler)(new AddIngredientToShoppingListCommand($user->getId(), $egg->getSlug()));
        } finally {
            $this->assertEquals(1, $sent->getIngredients()->reset()->getQuantity());
        }
    }
}
