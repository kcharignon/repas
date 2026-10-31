<?php

namespace Repas\Tests\Repas\Application;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\AddSpokenIngredient\AddSpokenIngredientCommand;
use Repas\Repas\Application\Command\AddSpokenIngredient\AddSpokenIngredientHandler;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListHandler;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Event\NewShoppingListCreatedEvent;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Repas\Domain\Model\AddedItem;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;

class AddSpokenIngredientHandlerTest extends TestCase
{
    private AddSpokenIngredientHandler $handler;
    private IngredientInMemoryRepository $ingredientRepository;
    private IngredientAliasInMemoryRepository $aliasRepository;
    private ShoppingListInMemoryRepository $shoppingListRepository;
    private SpyEventDispatcher $eventDispatcher;
    private ConversionInMemoryRepository $conversionRepository;

    protected function setUp(): void
    {
        $this->ingredientRepository = new IngredientInMemoryRepository();
        $this->aliasRepository = new IngredientAliasInMemoryRepository();
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();
        $this->eventDispatcher = new SpyEventDispatcher();
        $this->conversionRepository = new ConversionInMemoryRepository();
        $unitRepository = new UnitInMemoryRepository();
        foreach ([new UnitBuilder()->isUnite(), new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo(), new UnitBuilder()->isBox()] as $unit) {
            $unitRepository->save($unit->build());
        }

        $this->handler = new AddSpokenIngredientHandler(
            shoppingListRepository: $this->shoppingListRepository,
            ingredientRepository: $this->ingredientRepository,
            slugResolver: new IngredientSlugResolver($this->aliasRepository),
            createShoppingListHandler: new CreateShoppingListHandler(
                $this->shoppingListRepository,
                $this->eventDispatcher,
                new FrozenClock(new DateTimeImmutable()),
            ),
            unitRepository: $unitRepository,
            conversionService: new ConversionService($this->conversionRepository, $unitRepository),
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

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // La quantité et l'unité dites (ADR 0029 décision 5, amendée le 2026-10-25)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    public function testTheSaidQuantityIsAddedInThePurchaseUnit(): void
    {
        // Given
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($user)->build();
        $this->ingredientRepository->save($egg);

        // When
        $result = ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $egg->getSlug(), 'new-list-id', quantity: 3));

        // Then : trois unités d'achat, dans la ligne comme dans la liste des ingrédients ajoutés
        $this->assertEquals(new AddedItem(listCreated: true, previousListSent: false, quantity: 3.0, unitSlug: 'unite', unitName: 'unité', lineExisted: false, unitSubstituted: false, addedQuantity: 3.0), $result);
        $list = $this->shoppingListRepository->findOneById('new-list-id');
        $this->assertSame(3.0, $list->findRow($egg->getSlug())->getQuantity());
        $this->assertSame('unite', $list->findRow($egg->getSlug())->getUnitSlug());
        $this->assertSame(3.0, $list->getIngredients()->reset()->getQuantity());
    }

    public function testTheSaidUnitIsUsedWhenTheIngredientKnowsIt(): void
    {
        // Given : les pâtes s'achètent au gramme et se mesurent aussi au kilo
        $user = new UserBuilder()->build();
        $pasta = new IngredientBuilder()->isPasta()->withCreator($user)->build();
        $this->ingredientRepository->save($pasta);

        // When
        $result = ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $pasta->getSlug(), 'new-list-id', quantity: 2, unitSlug: 'kilo'));

        // Then : la ligne naît au kilo, rien n'est écarté
        $this->assertEquals(new AddedItem(listCreated: true, previousListSent: false, quantity: 2.0, unitSlug: 'kilo', unitName: 'kilo', lineExisted: false, unitSubstituted: false, addedQuantity: 2.0), $result);
        $row = $this->shoppingListRepository->findOneById('new-list-id')->findRow($pasta->getSlug());
        $this->assertSame('kilo', $row->getUnitSlug());
        $this->assertSame(2.0, $row->getQuantity());
    }

    public function testAnUnknownUnitIsSetAsideAndTheLineKeepsThePurchaseUnit(): void
    {
        // Given : un œuf ne se compte pas en paquets
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($user)->build();
        $this->ingredientRepository->save($egg);

        // When
        $result = ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $egg->getSlug(), 'new-list-id', quantity: 2, unitSlug: 'paquet'));

        // Then : deux unités d'achat, et le résultat dit que l'unité dite n'a pas servi
        $this->assertTrue($result->unitSubstituted);
        $this->assertSame('unite', $result->unitSlug);
        $this->assertSame(2.0, $result->quantity);
        $this->assertSame('unite', $this->shoppingListRepository->findOneById('new-list-id')->findRow($egg->getSlug())->getUnitSlug());
    }

    public function testAnIngredientAlreadyInTheListIsRaisedAndTheTotalIsReturned(): void
    {
        // Given : la ligne existe déjà avec 3 œufs
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($user)->build();
        $this->ingredientRepository->save($egg);
        ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $egg->getSlug(), 'new-list-id', quantity: 3));

        // When
        $result = ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $egg->getSlug(), 'unused-list-id', quantity: 2));

        // Then : 5 au total, la ligne existait, une seule ligne
        $this->assertEquals(new AddedItem(listCreated: false, previousListSent: false, quantity: 5.0, unitSlug: 'unite', unitName: 'unité', lineExisted: true, unitSubstituted: false, addedQuantity: 2.0), $result);
        $this->assertCount(1, $this->shoppingListRepository->findOneById('new-list-id')->getRows());
    }

    public function testAnUnitDifferentFromTheOneOfTheExistingLineIsConvertedIntoIt(): void
    {
        // Given : la ligne de pâtes est en grammes (une unité d'achat) et un kilo vaut mille grammes
        $user = new UserBuilder()->build();
        $pasta = new IngredientBuilder()->isPasta()->withCreator($user)->build();
        $this->ingredientRepository->save($pasta);
        $this->conversionRepository->save(new ConversionBuilder()->isKiloToGramme()->build());
        ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $pasta->getSlug(), 'new-list-id'));

        // When : deux kilos
        $result = ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $pasta->getSlug(), 'unused-list-id', quantity: 2, unitSlug: 'kilo'));

        // Then : la ligne garde le gramme, reçoit 2000 grammes, et le résultat dit que l'unité n'est pas celle de la ligne
        $this->assertEquals(new AddedItem(listCreated: false, previousListSent: false, quantity: 2001.0, unitSlug: 'gramme', unitName: 'gramme', lineExisted: true, unitSubstituted: true, addedQuantity: 2000.0), $result);
        $this->assertSame('gramme', $this->shoppingListRepository->findOneById('new-list-id')->findRow($pasta->getSlug())->getUnitSlug());
    }

    public function testWithoutAConversionTheSaidQuantityIsAddedAsIsInTheUnitOfTheLine(): void
    {
        // Given : aucune conversion connue entre le kilo et le gramme
        $user = new UserBuilder()->build();
        $pasta = new IngredientBuilder()->isPasta()->withCreator($user)->build();
        $this->ingredientRepository->save($pasta);
        ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $pasta->getSlug(), 'new-list-id'));

        // When
        $result = ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $pasta->getSlug(), 'unused-list-id', quantity: 2, unitSlug: 'kilo'));

        // Then : 2 dans l'unité de la ligne, dit autrement qu'en kilos
        $this->assertEquals(new AddedItem(listCreated: false, previousListSent: false, quantity: 3.0, unitSlug: 'gramme', unitName: 'gramme', lineExisted: true, unitSubstituted: true, addedQuantity: 2.0), $result);
    }

    public function testSayingTheUnitOfTheExistingLineIsNotASubstitution(): void
    {
        // Given
        $user = new UserBuilder()->build();
        $pasta = new IngredientBuilder()->isPasta()->withCreator($user)->build();
        $this->ingredientRepository->save($pasta);
        ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $pasta->getSlug(), 'new-list-id'));

        // When
        $result = ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $pasta->getSlug(), 'unused-list-id', quantity: 5, unitSlug: 'gramme'));

        // Then
        $this->assertFalse($result->unitSubstituted);
        $this->assertSame(6.0, $result->quantity);
    }

    #[DataProvider('unusableQuantities')]
    public function testAQuantityOutsideOneToNinetyNineWritesNothing(int $quantity): void
    {
        // Given
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($user)->build();
        $this->ingredientRepository->save($egg);

        // When
        try {
            ($this->handler)(new AddSpokenIngredientCommand($user->getId(), $egg->getSlug(), 'new-list-id', quantity: $quantity));
            $this->fail('Une quantité hors de 1 à 99 ne s’écrit pas.');
        } catch (ShoppingListException $exception) {
            // Then : 422, et la liste n'est même pas créée
            $this->assertSame(422, $exception->getCode());
            $this->assertCount(0, $this->shoppingListRepository->findByOwner($user->getId()));
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function unusableQuantities(): iterable
    {
        yield 'zéro' => [0];
        yield 'négative' => [-2];
        yield 'cent' => [100];
    }}
