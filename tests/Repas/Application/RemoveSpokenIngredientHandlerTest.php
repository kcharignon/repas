<?php

namespace Repas\Tests\Repas\Application;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\RemoveSpokenIngredient\RemoveSpokenIngredientCommand;
use Repas\Repas\Application\Command\RemoveSpokenIngredient\RemoveSpokenIngredientHandler;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Application\Service\SpokenListName;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Repas\Domain\Model\RemovedItem;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListTypeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;

/**
 * Retirer un article dit à la voix de la liste active (ADR 0029 décision 12), avec les dépôts en mémoire : aucune base, aucun noyau. La lecture
 * SQL se prouve dans `ShoppingListItemCommandOHSFlowTest`, le budget de requêtes dans `WriteBudgetTest`.
 */
class RemoveSpokenIngredientHandlerTest extends TestCase
{
    private RemoveSpokenIngredientHandler $handler;
    private IngredientInMemoryRepository $ingredientRepository;
    private IngredientAliasInMemoryRepository $aliasRepository;
    private ShoppingListInMemoryRepository $shoppingListRepository;
    private ConversionInMemoryRepository $conversionRepository;

    protected function setUp(): void
    {
        $this->ingredientRepository = new IngredientInMemoryRepository();
        $this->aliasRepository = new IngredientAliasInMemoryRepository();
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();
        $this->conversionRepository = new ConversionInMemoryRepository();
        $unitRepository = new UnitInMemoryRepository();
        foreach ([new UnitBuilder()->isUnite(), new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo(), new UnitBuilder()->isBox()] as $unit) {
            $unitRepository->save($unit->build());
        }

        $this->handler = new RemoveSpokenIngredientHandler(
            shoppingListRepository: $this->shoppingListRepository,
            slugResolver: new IngredientSlugResolver($this->aliasRepository),
            unitRepository: $unitRepository,
            ingredientRepository: $this->ingredientRepository,
            conversionService: new ConversionService($this->conversionRepository, $unitRepository),
            listName: new SpokenListName(new ShoppingListTypeInMemoryRepository()),
        );
    }

    /** Une liste active du propriétaire avec une ligne d'œufs de `$quantity` (et la même part ajoutée à la main), pour `$user`. */
    private function listWithEggs(float $quantity): array
    {
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($user)->build();
        $this->ingredientRepository->save($egg);
        $list = new ShoppingListBuilder()->withOwner($user)->build();
        $list->addIngredient($egg->getSlug(), 'unite', $quantity);
        $list->addRow($egg->getSlug(), 'unite', $quantity);
        $this->shoppingListRepository->save($list);

        return [$user, $egg, $list];
    }

    public function testRemovesOneFromTheLineByDefaultAndSaysWhatIsLeft(): void
    {
        // Given
        [$user, $egg, $list] = $this->listWithEggs(3);

        // When
        $result = ($this->handler)(new RemoveSpokenIngredientCommand($user->getId(), $egg->getSlug()));

        // Then : il en reste 2, dans la ligne comme dans la part ajoutée à la main
        $this->assertEquals(new RemovedItem(found: true, remaining: 2.0, removed: 1.0, unitSlug: 'unite', unitName: 'unité', unitSubstituted: false), $result);
        $this->assertSame(2.0, $list->findRow($egg->getSlug())->getQuantity());
        $this->assertSame(2.0, $list->getIngredients()->reset()->getQuantity());
    }

    public function testRemovesTheSaidQuantity(): void
    {
        // Given
        [$user, $egg, $list] = $this->listWithEggs(5);

        // When
        $result = ($this->handler)(new RemoveSpokenIngredientCommand($user->getId(), $egg->getSlug(), quantity: 3));

        // Then
        $this->assertSame(2.0, $result->remaining);
        $this->assertSame(3.0, $result->removed);
        $this->assertSame(2.0, $list->findRow($egg->getSlug())->getQuantity());
    }

    public function testTheLineDisappearsWhenEverythingIsRemoved(): void
    {
        // Given
        [$user, $egg, $list] = $this->listWithEggs(2);

        // When
        $result = ($this->handler)(new RemoveSpokenIngredientCommand($user->getId(), $egg->getSlug(), quantity: 2));

        // Then : plus de ligne, plus de part ajoutée à la main
        $this->assertTrue($result->lineDeleted());
        $this->assertSame(2.0, $result->removed);
        $this->assertNull($list->findRow($egg->getSlug()));
        $this->assertCount(0, $list->getRows());
        $this->assertCount(0, $list->getIngredients());
    }

    public function testTheLineDisappearsWhenMoreIsRemovedThanItHoldsAndNeverGoesNegative(): void
    {
        // Given
        [$user, $egg, $list] = $this->listWithEggs(2);

        // When : « enlève dix œufs » alors qu'il y en a deux
        $result = ($this->handler)(new RemoveSpokenIngredientCommand($user->getId(), $egg->getSlug(), quantity: 10));

        // Then : ce qui a été retiré est ce que la ligne contenait
        $this->assertTrue($result->lineDeleted());
        $this->assertSame(2.0, $result->removed);
        $this->assertCount(0, $list->getRows());
    }

    public function testOnlyTheHandAddedPartOfALineWithRecipesIsLowered(): void
    {
        // Given : la ligne d'œufs vaut 5 (3 venus d'une recette, 2 ajoutés à la main)
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($user)->build();
        $this->ingredientRepository->save($egg);
        $list = new ShoppingListBuilder()->withOwner($user)->build();
        $list->addRow($egg->getSlug(), 'unite', 3);
        $list->addIngredient($egg->getSlug(), 'unite', 2);
        $list->addRow($egg->getSlug(), 'unite', 2);
        $this->shoppingListRepository->save($list);

        // When : « enlève trois œufs »
        $result = ($this->handler)(new RemoveSpokenIngredientCommand($user->getId(), $egg->getSlug(), quantity: 3));

        // Then : la ligne passe à 2 ; la part ajoutée à la main ne descend pas sous zéro et disparaît
        $this->assertSame(2.0, $result->remaining);
        $this->assertSame(2.0, $list->findRow($egg->getSlug())->getQuantity());
        $this->assertCount(0, $list->getIngredients());
    }

    public function testNothingIsWrittenWhenThereIsNoActiveList(): void
    {
        // Given : aucune liste
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($user)->build();
        $this->ingredientRepository->save($egg);

        // When
        $result = ($this->handler)(new RemoveSpokenIngredientCommand($user->getId(), $egg->getSlug()));

        // Then : rien n'est trouvé, et surtout aucune liste n'est créée
        $this->assertFalse($result->found);
        $this->assertCount(0, $this->shoppingListRepository->findByOwner($user->getId()));
    }

    public function testASentListIsNeverModified(): void
    {
        // Given : la seule liste est partie au drive (ADR 0023)
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($user)->build();
        $this->ingredientRepository->save($egg);
        $sent = new ShoppingListBuilder()->withOwner($user)->withId('sent-id')->addIngredient($egg)->withStatus(ShoppingListStatus::SENT)->build();
        $this->shoppingListRepository->save($sent);

        // When
        $result = ($this->handler)(new RemoveSpokenIngredientCommand($user->getId(), $egg->getSlug()));

        // Then
        $this->assertFalse($result->found);
        $this->assertSame(1.0, $sent->findRow($egg->getSlug())->getQuantity());
        $this->assertSame(ShoppingListStatus::SENT, $sent->getStatus());
        $this->assertCount(1, $this->shoppingListRepository->findByOwner($user->getId()));
    }

    public function testAnIngredientThatIsNotInTheListChangesNothing(): void
    {
        // Given : la liste a du beurre, on retire des œufs
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($user)->build();
        $butter = new IngredientBuilder()->isButter()->withCreator($user)->build();
        $this->ingredientRepository->save($egg);
        $this->ingredientRepository->save($butter);
        $list = new ShoppingListBuilder()->withOwner($user)->addIngredient($butter)->build();
        $this->shoppingListRepository->save($list);

        // When
        $result = ($this->handler)(new RemoveSpokenIngredientCommand($user->getId(), $egg->getSlug()));

        // Then
        $this->assertFalse($result->found);
        $this->assertCount(1, $list->getRows());
        $this->assertSame(1.0, $list->findRow($butter->getSlug())->getQuantity());
    }

    public function testTheListOfAnotherUserIsNeverTouched(): void
    {
        // Given : l'autre utilisateur a une ligne d'œufs, l'utilisateur a une liste vide
        [, $egg, $othersList] = $this->listWithEggs(3);
        $user = new UserBuilder()->build();
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->build());

        // When
        $result = ($this->handler)(new RemoveSpokenIngredientCommand($user->getId(), $egg->getSlug()));

        // Then
        $this->assertFalse($result->found);
        $this->assertSame(3.0, $othersList->findRow($egg->getSlug())->getQuantity());
    }

    public function testRemovesFromTheCopyOfAnOldCommonTheUserReceived(): void
    {
        // Given : la ligne porte la copie de l'utilisateur, on lui donne l'ancien slug
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator(new UserBuilder()->withId('owner-id')->build())->build();
        $copy = new IngredientBuilder()->isEgg()->withCreator($user, true)->withAdoptedFrom($egg->getSlug())->build();
        $this->ingredientRepository->save($egg);
        $this->ingredientRepository->save($copy);
        $this->aliasRepository->save(IngredientAlias::create($user->getId(), $egg->getSlug(), $copy->getSlug(), AliasKind::ADOPTION, 'activation', new DateTimeImmutable()));
        $list = new ShoppingListBuilder()->withOwner($user)->build();
        $list->addIngredient($copy->getSlug(), 'unite', 3);
        $list->addRow($copy->getSlug(), 'unite', 3);
        $this->shoppingListRepository->save($list);

        // When
        $result = ($this->handler)(new RemoveSpokenIngredientCommand($user->getId(), $egg->getSlug()));

        // Then
        $this->assertSame(2.0, $result->remaining);
        $this->assertSame(2.0, $list->findRow($copy->getSlug())->getQuantity());
    }

    #[DataProvider('invalidQuantities')]
    public function testRefusesAQuantityOutsideOneToNinetyNineAndWritesNothing(int $quantity): void
    {
        // Given
        [$user, $egg, $list] = $this->listWithEggs(3);

        // When
        try {
            ($this->handler)(new RemoveSpokenIngredientCommand($user->getId(), $egg->getSlug(), quantity: $quantity));
            $this->fail('Une quantité hors de 1 à 99 ne se retire pas.');
        } catch (ShoppingListException $exception) {
            // Then
            $this->assertEquals(ShoppingListException::invalidSpokenQuantity($quantity), $exception);
            $this->assertSame(3.0, $list->findRow($egg->getSlug())->getQuantity());
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidQuantities(): iterable
    {
        yield 'zéro' => [0];
        yield 'négative' => [-2];
        yield 'cent' => [100];
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // L'unité dite : la même règle que l'ajout (`SpokenLineQuantity`)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    public function testASaidUnitIsConvertedIntoTheUnitOfTheLine(): void
    {
        // Given : la ligne de pâtes est en grammes (2500), un kilo vaut mille grammes
        $user = new UserBuilder()->build();
        $pasta = new IngredientBuilder()->isPasta()->withCreator($user)->build();
        $this->ingredientRepository->save($pasta);
        $this->conversionRepository->save(new ConversionBuilder()->isKiloToGramme()->build());
        $list = new ShoppingListBuilder()->withOwner($user)->build();
        $list->addIngredient($pasta->getSlug(), 'gramme', 2500);
        $list->addRow($pasta->getSlug(), 'gramme', 2500);
        $this->shoppingListRepository->save($list);

        // When : « enlève un kilo de pâtes »
        $result = ($this->handler)(new RemoveSpokenIngredientCommand($user->getId(), $pasta->getSlug(), quantity: 1, unitSlug: 'kilo'));

        // Then : mille grammes de moins, et le résultat dit que l'unité dite n'est pas celle de la ligne
        $this->assertEquals(new RemovedItem(found: true, remaining: 1500.0, removed: 1000.0, unitSlug: 'gramme', unitName: 'gramme', unitSubstituted: true), $result);
        $this->assertSame(1500.0, $list->findRow($pasta->getSlug())->getQuantity());
    }

    public function testAnUnknownUnitIsSetAsideAndTheQuantityIsRemovedInTheUnitOfTheLine(): void
    {
        // Given : un œuf ne se compte pas en paquets
        [$user, $egg, $list] = $this->listWithEggs(4);

        // When : « enlève deux paquets d'œufs »
        $result = ($this->handler)(new RemoveSpokenIngredientCommand($user->getId(), $egg->getSlug(), quantity: 2, unitSlug: 'paquet'));

        // Then : deux œufs de moins, et Alexa le dit
        $this->assertTrue($result->unitSubstituted);
        $this->assertSame('unite', $result->unitSlug);
        $this->assertSame(2.0, $result->remaining);
        $this->assertSame(2.0, $list->findRow($egg->getSlug())->getQuantity());
    }

    public function testTheLineOfAnotherUnitWithoutConversionIsLoweredByTheSaidQuantityAsIs(): void
    {
        // Given : la ligne de pâtes est en grammes, aucune conversion kilo -> gramme n'existe
        $user = new UserBuilder()->build();
        $pasta = new IngredientBuilder()->isPasta()->withCreator($user)->build();
        $this->ingredientRepository->save($pasta);
        $list = new ShoppingListBuilder()->withOwner($user)->build();
        $list->addIngredient($pasta->getSlug(), 'gramme', 500);
        $list->addRow($pasta->getSlug(), 'gramme', 500);
        $this->shoppingListRepository->save($list);

        // When : « enlève deux kilos de pâtes »
        $result = ($this->handler)(new RemoveSpokenIngredientCommand($user->getId(), $pasta->getSlug(), quantity: 2, unitSlug: 'kilo'));

        // Then : deux grammes de moins (la quantité dite telle quelle), l'unité de la ligne est dite
        $this->assertTrue($result->unitSubstituted);
        $this->assertSame(498.0, $result->remaining);
    }

    public function testTheListIsTheOnlyThingWrittenAndItsVersionMoves(): void
    {
        // Given
        [$user, $egg, $list] = $this->listWithEggs(3);
        $version = $list->getVersion();

        // When
        ($this->handler)(new RemoveSpokenIngredientCommand($user->getId(), $egg->getSlug()));

        // Then : la liste enregistrée est la même, dans son état d'après
        $saved = $this->shoppingListRepository->findOneActivateByOwner($user->getId());
        $this->assertInstanceOf(ShoppingList::class, $saved);
        $this->assertSame(2.0, $saved->findRow($egg->getSlug())->getQuantity());
        $this->assertGreaterThanOrEqual($version, $saved->getVersion());
    }
}
