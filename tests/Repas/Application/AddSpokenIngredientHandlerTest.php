<?php

namespace Repas\Tests\Repas\Application;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\AddSpokenIngredient\AddSpokenIngredientCommand;
use Repas\Repas\Application\Command\AddSpokenIngredient\AddSpokenIngredientHandler;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListHandler;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Event\NewShoppingListCreatedEvent;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Application\Interface\AddedItem;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;

class AddSpokenIngredientHandlerTest extends TestCase
{
    private AddSpokenIngredientHandler $handler;
    private IngredientInMemoryRepository $ingredientRepository;
    private IngredientAliasInMemoryRepository $aliasRepository;
    private ShoppingListInMemoryRepository $shoppingListRepository;
    private SpyEventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        $this->ingredientRepository = new IngredientInMemoryRepository();
        $this->aliasRepository = new IngredientAliasInMemoryRepository();
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();
        $this->eventDispatcher = new SpyEventDispatcher();

        $this->handler = new AddSpokenIngredientHandler(
            shoppingListRepository: $this->shoppingListRepository,
            ingredientRepository: $this->ingredientRepository,
            slugResolver: new IngredientSlugResolver($this->aliasRepository),
            createShoppingListHandler: new CreateShoppingListHandler(
                $this->shoppingListRepository,
                $this->eventDispatcher,
                new FrozenClock(new DateTimeImmutable()),
            ),
        );
    }

    public function testAddsALineToTheActiveListWithoutCreatingAnother(): void
    {
        // Given
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($user)->build();
        $this->ingredientRepository->save($egg);
        $active = new ShoppingListBuilder()->withOwner($user)->build();
        $this->shoppingListRepository->save($active);

        // When
        $result = ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $egg->getSlug(), 'new-list-id'));

        // Then
        $this->assertEquals(new AddedItem(listCreated: false, previousListSent: false), $result);
        $this->assertCount(1, $active->getRows());
        $this->assertSame(1.0, (float) $active->getRows()->reset()->getQuantity());
        $this->assertSame($egg->getSlug(), $active->getRows()->reset()->getIngredientSlug());
        $this->assertCount(1, $this->shoppingListRepository->findByOwner($user->getId()));
        $this->assertSame([], $this->eventDispatcher->eventDispatched);
    }

    public function testCreatesAnActiveListWhenTheUserHasNone(): void
    {
        // Given
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($user)->build();
        $this->ingredientRepository->save($egg);

        // When
        $result = ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $egg->getSlug(), 'new-list-id'));

        // Then
        $this->assertEquals(new AddedItem(listCreated: true, previousListSent: false), $result);
        $created = $this->shoppingListRepository->findOneById('new-list-id');
        $this->assertSame(ShoppingListStatus::ACTIVE, $created->getStatus());
        $this->assertSame($user->getId(), $created->getOwnerId());
        $this->assertCount(1, $created->getRows());
        $this->assertSame($egg->getSlug(), $created->getRows()->reset()->getIngredientSlug());
        $this->assertEquals([new NewShoppingListCreatedEvent('new-list-id', $user->getId())], $this->eventDispatcher->eventDispatched);
    }

    public function testCreatesANewListAndLeavesTheSentListUntouched(): void
    {
        // Given : la seule liste du propriétaire est envoyée au drive (ADR 0023)
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($user)->build();
        $this->ingredientRepository->save($egg);
        $sent = new ShoppingListBuilder()->withOwner($user)->withId('sent-id')->addIngredient(new IngredientBuilder()->isButter())->withStatus(ShoppingListStatus::SENT)->build();
        $sentAt = $sent->getSentAt();
        $this->shoppingListRepository->save($sent);

        // When
        $result = ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $egg->getSlug(), 'new-list-id'));

        // Then
        $this->assertEquals(new AddedItem(listCreated: true, previousListSent: true), $result);
        $this->assertSame(ShoppingListStatus::SENT, $sent->getStatus());
        $this->assertSame($sentAt, $sent->getSentAt());
        $this->assertCount(1, $sent->getRows());
        $this->assertSame(ShoppingListStatus::ACTIVE, $this->shoppingListRepository->findOneById('new-list-id')->getStatus());
        $this->assertCount(1, $this->shoppingListRepository->findOneById('new-list-id')->getRows());
    }

    public function testIgnoresTheSentListOfAnotherUser(): void
    {
        // Given : seul un autre utilisateur a une liste envoyée
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($user)->build();
        $this->ingredientRepository->save($egg);
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner(new UserBuilder()->build())->addIngredient(new IngredientBuilder()->isButter())->withStatus(ShoppingListStatus::SENT)->build());

        // When
        $result = ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $egg->getSlug(), 'new-list-id'));

        // Then
        $this->assertEquals(new AddedItem(listCreated: true, previousListSent: false), $result);
    }

    public function testSayingTheSameIngredientTwiceRaisesTheExistingLine(): void
    {
        // Given
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($user)->build();
        $this->ingredientRepository->save($egg);

        // When : une première fois sans liste, puis une seconde sur la liste créée
        $first = ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $egg->getSlug(), 'new-list-id'));
        $second = ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $egg->getSlug(), 'unused-list-id'));

        // Then : une seule ligne, deux unités ; la seconde fois ne crée rien
        $this->assertTrue($first->listCreated);
        $this->assertFalse($second->listCreated);
        $list = $this->shoppingListRepository->findOneById('new-list-id');
        $this->assertCount(1, $list->getRows());
        $this->assertSame(2.0, (float) $list->getRows()->reset()->getQuantity());
        $this->assertCount(1, $this->shoppingListRepository->findByOwner($user->getId()));
    }

    public function testRefusesAnUnknownIngredientAndWritesNothing(): void
    {
        // Given : le propriétaire n'a pas de liste active, l'ingrédient n'existe pas
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->build();

        // When
        try {
            ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $egg->getSlug(), 'new-list-id'));
            $this->fail('Un ingrédient inconnu ne se pose pas dans la liste.');
        } catch (IngredientException $exception) {
            // Then : 404, et la liste n'est même pas créée
            $this->assertEquals(IngredientException::notFound($egg->getSlug()), $exception);
            $this->assertCount(0, $this->shoppingListRepository->findByOwner($user->getId()));
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }

    public function testRefusesTheIngredientOfAnotherUserAndLeavesTheActiveListIntact(): void
    {
        // Given
        $user = new UserBuilder()->build();
        $butter = new IngredientBuilder()->isButter()->withCreator(new UserBuilder()->build(), true)->build();
        $this->ingredientRepository->save($butter);
        $active = new ShoppingListBuilder()->withOwner($user)->build();
        $this->shoppingListRepository->save($active);

        // When
        try {
            ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $butter->getSlug(), 'new-list-id'));
            $this->fail("L'ingrédient d'un autre utilisateur ne se pose pas dans la liste.");
        } catch (IngredientException $exception) {
            // Then
            $this->assertEquals(IngredientException::notFound($butter->getSlug()), $exception);
            $this->assertCount(0, $active->getRows());
            $this->assertCount(0, $active->getIngredients());
            $this->assertSame(ShoppingListStatus::ACTIVE, $active->getStatus());
        }
    }

    public function testRefusesTheIngredientOfAnotherUserWithoutCreatingAList(): void
    {
        // Given : pas de liste, l'ingrédient est à un autre
        $user = new UserBuilder()->build();
        $butter = new IngredientBuilder()->isButter()->withCreator(new UserBuilder()->build(), true)->build();
        $this->ingredientRepository->save($butter);

        // When
        try {
            ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $butter->getSlug(), 'new-list-id'));
            $this->fail("L'ingrédient d'un autre utilisateur ne se pose pas dans la liste.");
        } catch (IngredientException $exception) {
            // Then
            $this->assertEquals(IngredientException::notFound($butter->getSlug()), $exception);
            $this->assertCount(0, $this->shoppingListRepository->findByOwner($user->getId()));
        }
    }

    public function testAddsTheCopyOfAnOldCommonTheUserReceived(): void
    {
        // Given : l'utilisateur a reçu une copie de l'œuf du catalogue ; on lui donne l'ancien slug
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator(new UserBuilder()->withId('owner-id')->build())->build();
        $copy = new IngredientBuilder()->isEgg()->withCreator($user, true)->withAdoptedFrom($egg->getSlug())->build();
        $this->ingredientRepository->save($egg);
        $this->ingredientRepository->save($copy);
        $this->aliasRepository->save(IngredientAlias::create($user->getId(), $egg->getSlug(), $copy->getSlug(), AliasKind::ADOPTION, 'activation', new DateTimeImmutable()));

        // When
        $result = ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $egg->getSlug(), 'new-list-id'));

        // Then : la ligne porte la copie, jamais l'ancien commun
        $this->assertTrue($result->listCreated);
        $list = $this->shoppingListRepository->findOneById('new-list-id');
        $this->assertSame([$copy->getSlug()], array_values(array_map(static fn($row) => $row->getIngredientSlug(), $list->getRows()->toArray())));
    }
}
