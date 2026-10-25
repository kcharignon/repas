<?php

namespace Repas\Tests\Repas\Infrastructure\Voice;

use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Application\Interface\AddedItem;
use Repas\Shared\Application\Interface\RemovedItem;
use Repas\Shared\Application\Interface\VoiceShoppingList;
use Repas\Tests\Helper\Builder\ConversionBuilder;
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
        $this->assertEquals(new AddedItem(listCreated: false, previousListSent: false, unitSlug: 'litre', unitName: 'litre'), $result);
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
        $this->assertEquals(new AddedItem(listCreated: true, previousListSent: false, unitSlug: 'litre', unitName: 'litre'), $result);
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
        $this->assertEquals(new AddedItem(listCreated: true, previousListSent: true, unitSlug: 'litre', unitName: 'litre'), $result);
        $reloaded = $this->shoppingListRepository->findOneById($sent->getId());
        $this->assertSame(ShoppingListStatus::SENT, $reloaded->getStatus());
        $this->assertSame($sent->getVersion(), $reloaded->getVersion());
        $this->assertCount(1, $reloaded->getRows());
        $this->assertCount(2, $this->shoppingListRepository->findByOwner($user->getId()));
        $this->assertNotNull($this->shoppingListRepository->findOneActivateByOwner($user->getId()));
    }

    public function testSayingTheSameIngredientTwiceRaisesTheLineAndTheTotalIsTheLineOne(): void
    {
        // Given : une ligne de lait existe déjà dans la liste active
        $user = $this->savedUser();
        $milk = $this->savedMilk($user);
        $this->voiceShoppingList->addItem($user->getId(), $milk->getSlug());
        $this->entityManager->clear();

        // When : deux litres de plus
        $result = $this->voiceShoppingList->addItem($user->getId(), $milk->getSlug(), 2);
        $this->entityManager->clear();

        // Then : la même ligne, 3 litres au total, dans l'unité de la ligne
        $this->assertEquals(new AddedItem(listCreated: false, previousListSent: false, quantity: 3.0, unitSlug: 'litre', unitName: 'litre', lineExisted: true, unitSubstituted: false, addedQuantity: 2.0), $result);
        $rows = $this->shoppingListRepository->findOneActivateByOwner($user->getId())->getRows();
        $this->assertCount(1, $rows);
        $this->assertSame(3.0, $rows->reset()->getQuantity());
        $this->assertSame('litre', $rows->reset()->getUnitSlug());
    }

    public function testTheSaidUnitIsConvertedIntoTheUnitOfTheExistingLine(): void
    {
        // Given : des pâtes au gramme déjà dans la liste, et la conversion kilo -> gramme
        $user = $this->savedUser();
        $pasta = new IngredientBuilder()->isPasta()->withCreator($user)->build();
        $this->persister->saveIngredient($pasta);
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $this->voiceShoppingList->addItem($user->getId(), $pasta->getSlug());
        $this->entityManager->clear();

        // When : deux kilos
        $result = $this->voiceShoppingList->addItem($user->getId(), $pasta->getSlug(), 2, 'kilo');
        $this->entityManager->clear();

        // Then
        $this->assertEquals(new AddedItem(listCreated: false, previousListSent: false, quantity: 2001.0, unitSlug: 'gramme', unitName: 'gramme', lineExisted: true, unitSubstituted: true, addedQuantity: 2000.0), $result);
        $this->assertSame(2001.0, $this->shoppingListRepository->findOneActivateByOwner($user->getId())->getRows()->reset()->getQuantity());
    }

    public function testAKnownUnitStartsTheLineInThatUnitAndAnUnknownOneIsSetAside(): void
    {
        // Given
        $user = $this->savedUser();
        $pasta = new IngredientBuilder()->isPasta()->withCreator($user)->build();
        $milk = $this->savedMilk($user);
        $this->persister->saveIngredient($pasta);

        // When
        $kilo = $this->voiceShoppingList->addItem($user->getId(), $pasta->getSlug(), 2, 'kilo');
        $setAside = $this->voiceShoppingList->addItem($user->getId(), $milk->getSlug(), 3, 'paquet');
        $this->entityManager->clear();

        // Then : les pâtes au kilo, le lait garde le litre et le dit
        $this->assertSame('kilo', $kilo->unitSlug);
        $this->assertFalse($kilo->unitSubstituted);
        $this->assertSame('litre', $setAside->unitSlug);
        $this->assertTrue($setAside->unitSubstituted);
        $units = [];
        foreach ($this->shoppingListRepository->findOneActivateByOwner($user->getId())->getRows() as $row) {
            $units[$row->getIngredientSlug()] = [$row->getUnitSlug(), $row->getQuantity()];
        }
        $this->assertSame(['kilo', 2.0], $units[$pasta->getSlug()]);
        $this->assertSame(['litre', 3.0], $units[$milk->getSlug()]);
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

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Retirer de la liste (ADR 0029 décision 12)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    public function testRemovesTheSaidQuantityFromTheActiveLineAndSavesIt(): void
    {
        // Given : cinq litres de lait dans la liste active
        $user = $this->savedUser();
        $milk = $this->savedMilk($user);
        $this->voiceShoppingList->addItem($user->getId(), $milk->getSlug(), 5);
        $this->entityManager->clear();
        $version = $this->shoppingListRepository->findOneActivateByOwner($user->getId())->getVersion();
        $this->entityManager->clear();

        // When : « enlève deux litres de lait »
        $result = $this->voiceShoppingList->removeItem($user->getId(), $milk->getSlug(), 2);
        $this->entityManager->clear();

        // Then : il en reste 3, la même ligne, la liste a une version de plus
        $this->assertEquals(new RemovedItem(found: true, remaining: 3.0, removed: 2.0, unitSlug: 'litre', unitName: 'litre', unitSubstituted: false), $result);
        $reloaded = $this->shoppingListRepository->findOneActivateByOwner($user->getId());
        $this->assertSame(3.0, $reloaded->getRows()->reset()->getQuantity());
        $this->assertSame(3.0, $reloaded->getIngredients()->reset()->getQuantity());
        $this->assertSame($version + 1, $reloaded->getVersion());
        $this->assertCount(1, $this->shoppingListRepository->findByOwner($user->getId()));
    }

    public function testRemovesTheWholeLineAndLeavesNoRowNorHandAddedPartBehind(): void
    {
        // Given
        $user = $this->savedUser();
        $milk = $this->savedMilk($user);
        $this->voiceShoppingList->addItem($user->getId(), $milk->getSlug(), 2);
        $this->entityManager->clear();

        // When : plus que ce qu'il y a
        $result = $this->voiceShoppingList->removeItem($user->getId(), $milk->getSlug(), 10);
        $this->entityManager->clear();

        // Then
        $this->assertTrue($result->lineDeleted());
        $this->assertSame(2.0, $result->removed);
        $reloaded = $this->shoppingListRepository->findOneActivateByOwner($user->getId());
        $this->assertCount(0, $reloaded->getRows());
        $this->assertCount(0, $reloaded->getIngredients());
    }

    public function testRemovingNeverCreatesAListNorTouchesASentOne(): void
    {
        // Given : la seule liste est partie au drive
        $user = $this->savedUser();
        $milk = $this->savedMilk($user);
        $sent = new ShoppingListBuilder()->withOwner($user)->addIngredient($milk)->withStatus(ShoppingListStatus::SENT)->build();
        $this->shoppingListRepository->save($sent);
        $this->entityManager->clear();

        // When
        $result = $this->voiceShoppingList->removeItem($user->getId(), $milk->getSlug());
        $this->entityManager->clear();

        // Then : rien n'est trouvé, la liste envoyée est intacte et aucune liste n'a été créée
        $this->assertFalse($result->found);
        $reloaded = $this->shoppingListRepository->findOneById($sent->getId());
        $this->assertSame(ShoppingListStatus::SENT, $reloaded->getStatus());
        $this->assertSame($sent->getVersion(), $reloaded->getVersion());
        $this->assertCount(1, $reloaded->getRows());
        $this->assertCount(1, $this->shoppingListRepository->findByOwner($user->getId()));
    }

    public function testRemovingFromAUserWithoutAnyListCreatesNothing(): void
    {
        // Given
        $user = $this->savedUser();
        $milk = $this->savedMilk($user);

        // When
        $result = $this->voiceShoppingList->removeItem($user->getId(), $milk->getSlug());
        $this->entityManager->clear();

        // Then
        $this->assertFalse($result->found);
        $this->assertCount(0, $this->shoppingListRepository->findByOwner($user->getId()));
    }

    public function testRemovingAnIngredientThatIsNotInTheListChangesNothing(): void
    {
        // Given : du lait dans la liste, on retire du beurre
        $user = $this->savedUser();
        $milk = $this->savedMilk($user);
        $butter = new IngredientBuilder()->isButter()->withCreator($user)->build();
        $this->persister->saveIngredient($butter);
        $this->voiceShoppingList->addItem($user->getId(), $milk->getSlug());
        $this->entityManager->clear();
        $version = $this->shoppingListRepository->findOneActivateByOwner($user->getId())->getVersion();
        $this->entityManager->clear();

        // When
        $result = $this->voiceShoppingList->removeItem($user->getId(), $butter->getSlug());
        $this->entityManager->clear();

        // Then : même version, même ligne
        $this->assertFalse($result->found);
        $reloaded = $this->shoppingListRepository->findOneActivateByOwner($user->getId());
        $this->assertSame($version, $reloaded->getVersion());
        $this->assertCount(1, $reloaded->getRows());
    }

    public function testTheSaidUnitIsConvertedIntoTheUnitOfTheLineWhenRemoving(): void
    {
        // Given : 2 kilos de pâtes ajoutés (au gramme, 1 puis 2000), et la conversion kilo -> gramme
        $user = $this->savedUser();
        $pasta = new IngredientBuilder()->isPasta()->withCreator($user)->build();
        $this->persister->saveIngredient($pasta);
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $this->voiceShoppingList->addItem($user->getId(), $pasta->getSlug());
        $this->voiceShoppingList->addItem($user->getId(), $pasta->getSlug(), 2, 'kilo');
        $this->entityManager->clear();

        // When : « enlève un kilo de pâtes »
        $result = $this->voiceShoppingList->removeItem($user->getId(), $pasta->getSlug(), 1, 'kilo');
        $this->entityManager->clear();

        // Then : mille grammes de moins
        $this->assertEquals(new RemovedItem(found: true, remaining: 1001.0, removed: 1000.0, unitSlug: 'gramme', unitName: 'gramme', unitSubstituted: true), $result);
        $this->assertSame(1001.0, $this->shoppingListRepository->findOneActivateByOwner($user->getId())->getRows()->reset()->getQuantity());
    }

    public function testRefusesAQuantityOutsideOneToNinetyNineWhenRemoving(): void
    {
        // Given
        $user = $this->savedUser();
        $milk = $this->savedMilk($user);
        $this->voiceShoppingList->addItem($user->getId(), $milk->getSlug(), 3);
        $this->entityManager->clear();

        // When
        try {
            $this->voiceShoppingList->removeItem($user->getId(), $milk->getSlug(), 100);
            $this->fail('Une quantité hors de 1 à 99 ne se retire pas.');
        } catch (ShoppingListException $exception) {
            // Then : rien n'est écrit
            $this->entityManager->clear();
            $this->assertEquals(ShoppingListException::invalidSpokenQuantity(100), $exception);
            $this->assertSame(3.0, $this->shoppingListRepository->findOneActivateByOwner($user->getId())->getRows()->reset()->getQuantity());
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
