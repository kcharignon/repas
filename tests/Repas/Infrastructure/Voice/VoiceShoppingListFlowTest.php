<?php

namespace Repas\Tests\Repas\Infrastructure\Voice;

use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Application\Interface\AddedItem;
use Repas\Shared\Application\Interface\VoiceShoppingList;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Model\User;

/**
 * Le port `VoiceShoppingList` câblé pour de vrai : adaptateur, bus transactionnel, handler et repositories sur la base de test.
 */
class VoiceShoppingListFlowTest extends DatabaseTestCase
{
    private VoiceShoppingList $voiceShoppingList;
    private ShoppingListRepository $shoppingListRepository;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->voiceShoppingList = static::getContainer()->get(VoiceShoppingList::class);
        $this->shoppingListRepository = static::getContainer()->get(ShoppingListRepository::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testAddsALineToTheActiveListAndRaisesItsVersion(): void
    {
        // Given
        $user = $this->savedUser();
        $milk = $this->savedMilk($user);
        $active = new ShoppingListBuilder()->withOwner($user)->build();
        $this->shoppingListRepository->save($active);
        $this->entityManager->clear();

        // When
        $result = $this->voiceShoppingList->addItem($user->getId(), $milk->getSlug());
        $this->entityManager->clear();

        // Then
        $this->assertEquals(new AddedItem(listCreated: false, previousListSent: false), $result);
        $reloaded = $this->shoppingListRepository->findOneById($active->getId());
        $this->assertCount(1, $reloaded->getRows());
        $this->assertSame($active->getVersion() + 1, $reloaded->getVersion());
        $this->assertCount(1, $this->shoppingListRepository->findByOwner($user->getId()));
    }

    public function testCreatesTheActiveListWhenTheUserHasNone(): void
    {
        // Given
        $user = $this->savedUser();
        $milk = $this->savedMilk($user);

        // When
        $result = $this->voiceShoppingList->addItem($user->getId(), $milk->getSlug());
        $this->entityManager->clear();

        // Then
        $this->assertEquals(new AddedItem(listCreated: true, previousListSent: false), $result);
        $lists = $this->shoppingListRepository->findByOwner($user->getId());
        $this->assertCount(1, $lists);
        $this->assertSame(ShoppingListStatus::ACTIVE, $lists->reset()->getStatus());
        $this->assertCount(1, $lists->reset()->getRows());
    }

    public function testCreatesANewListWhenTheOnlyListIsSentAndLeavesItIntact(): void
    {
        // Given
        $user = $this->savedUser();
        $milk = $this->savedMilk($user);
        $butter = new IngredientBuilder()->isButter()->withCreator($user)->build();
        $this->persister->saveIngredient($butter);
        $sent = new ShoppingListBuilder()->withOwner($user)->addIngredient($butter)->withStatus(ShoppingListStatus::SENT)->build();
        $this->shoppingListRepository->save($sent);
        $this->entityManager->clear();

        // When
        $result = $this->voiceShoppingList->addItem($user->getId(), $milk->getSlug());
        $this->entityManager->clear();

        // Then
        $this->assertEquals(new AddedItem(listCreated: true, previousListSent: true), $result);
        $reloaded = $this->shoppingListRepository->findOneById($sent->getId());
        $this->assertSame(ShoppingListStatus::SENT, $reloaded->getStatus());
        $this->assertSame($sent->getVersion(), $reloaded->getVersion());
        $this->assertCount(1, $reloaded->getRows());
        $this->assertCount(2, $this->shoppingListRepository->findByOwner($user->getId()));
        $this->assertNotNull($this->shoppingListRepository->findOneActivateByOwner($user->getId()));
    }

    public function testRefusesTheIngredientOfAnotherUserAndWritesNothing(): void
    {
        // Given : pas de liste, l'ingrédient est à un autre
        $user = $this->savedUser();
        $milk = $this->savedMilk($this->savedUser());

        // When
        try {
            $this->voiceShoppingList->addItem($user->getId(), $milk->getSlug());
            $this->fail("L'ingrédient d'un autre utilisateur ne se pose pas dans la liste.");
        } catch (IngredientException $exception) {
            // Then : 404, et aucune liste n'a été créée
            $this->assertEquals(IngredientException::notFound($milk->getSlug()), $exception);
            $this->entityManager->clear();
            $this->assertCount(0, $this->shoppingListRepository->findByOwner($user->getId()));
        }
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }

    private function savedMilk(User $owner): Ingredient
    {
        $milk = new IngredientBuilder()->isMilk()->withCreator($owner)->build();
        $this->persister->saveIngredient($milk);

        return $milk;
    }
}
