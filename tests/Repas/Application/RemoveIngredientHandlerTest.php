<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\RemoveIngredient\RemoveIngredientCommand;
use Repas\Repas\Application\Command\RemoveIngredient\RemoveIngredientHandler;
use Repas\Shared\Domain\Event\IngredientRemovedEvent;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\IngredientDistinctPair;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientDistinctInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;

class RemoveIngredientHandlerTest extends TestCase
{
    private readonly RemoveIngredientHandler $handler;
    private readonly IngredientRepository $ingredientRepository;
    private readonly RecipeRepository $recipeRepository;
    private readonly ShoppingListRepository $shoppingListRepository;
    private readonly ConversionRepository $conversionRepository;
    private readonly IngredientAliasInMemoryRepository $aliasRepository;
    private readonly IngredientDistinctInMemoryRepository $distinctRepository;
    private readonly SpyEventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        $this->ingredientRepository = new IngredientInMemoryRepository([
            // Un ingrédient du propriétaire du catalogue (un ancien commun)
            new IngredientBuilder()->isSugar()->withCreator(new UserBuilder()->withId('owner-id')->build())->build(),
            new IngredientBuilder()->isFlour()->build(),
            new IngredientBuilder()->isPasta()->build(),
            new IngredientBuilder()->isMilk()->withCreator(new UserBuilder()->withId('user-id'))->build(),
            // Une copie d'adoption du commun « beurre » par l'utilisateur
            new IngredientBuilder()->isButter()->withCreator(new UserBuilder()->withId('user-id')->build(), true)->withAdoptedFrom('beurre')->build(),
        ]);
        $this->recipeRepository = new RecipeInMemoryRepository([
            new RecipeBuilder()->isPastaCarbonara()->build(),
        ]);
        $this->shoppingListRepository = new ShoppingListInMemoryRepository([
            new ShoppingListBuilder()->addIngredient(new IngredientBuilder()->isFlour())->build()
        ]);
        $this->conversionRepository = new ConversionInMemoryRepository([
           new ConversionBuilder()
               ->withIngredient(new IngredientBuilder()->isSugar())
               ->withStartUnit(new UnitBuilder()->isBox()->build())
               ->withEndUnit(new UnitBuilder()->isGramme()->build())
               ->withCoefficient(1000)
               ->build(),
        ]);
        $this->aliasRepository = new IngredientAliasInMemoryRepository([
            IngredientAlias::create('user-id', 'beurre', 'beurreuser-id', AliasKind::ADOPTION, 'first-use', new \DateTimeImmutable()),
            IngredientAlias::create('user-id', 'ancien-lait', 'lait', AliasKind::MERGE, 'merge', new \DateTimeImmutable()),
            IngredientAlias::create('other-id', 'beurre', 'beurreother-id', AliasKind::ADOPTION, 'first-use', new \DateTimeImmutable()),
        ]);
        $this->distinctRepository = new IngredientDistinctInMemoryRepository([
            IngredientDistinctPair::create('user-id', 'lait', 'beurreuser-id', new \DateTimeImmutable()),
            IngredientDistinctPair::create('user-id', 'beurreuser-id', 'cremeuser-id', new \DateTimeImmutable()),
            IngredientDistinctPair::create('other-id', 'beurreuser-id', 'cremeuser-id', new \DateTimeImmutable()),
        ]);
        $this->eventDispatcher = new SpyEventDispatcher();

        $this->handler = new RemoveIngredientHandler(
            $this->ingredientRepository,
            $this->recipeRepository,
            $this->shoppingListRepository,
            $this->conversionRepository,
            $this->aliasRepository,
            $this->distinctRepository,
            $this->eventDispatcher,
        );
    }


    public function testRemoveIngredientSuccessForTheIngredientOfTheCatalogOwner(): void
    {
        // Arrange
        $command = new RemoveIngredientCommand('sucre');

        // Act
        ($this->handler)($command);

        // Assert
        $conversions = $this->conversionRepository->findByIngredientOrCommon(new IngredientBuilder()->isSugar()->build())->filter(fn(Conversion $conversion) => $conversion->getIngredient() !== null);
        $this->assertCount(0, $conversions);

        // Tout ingrédient a un propriétaire : sa suppression est annoncée aux statistiques de celui-ci, comme pour tout autre
        $this->assertEquals(new IngredientRemovedEvent('owner-id', 'sucre'), $this->eventDispatcher->getLastEventDispatched());

        $this->expectExceptionObject(IngredientException::notFound('sucre'));
        $this->ingredientRepository->findOneBySlug('sucre');
    }

    public function testRemoveIngredientSuccessForUserIngredient(): void
    {
        // Arrange
        $command = new RemoveIngredientCommand('lait');

        // Act
        ($this->handler)($command);

        // Assert
        $expectedEvent = new IngredientRemovedEvent('user-id', 'lait');
        $actualEvent = $this->eventDispatcher->getLastEventDispatched();
        $this->assertEquals($expectedEvent, $actualEvent);

        $this->expectExceptionObject(IngredientException::notFound('lait'));
        $this->ingredientRepository->findOneBySlug('lait');
    }

    public function testRemovingAUserIngredientRemovesTheAliasesThatDesignateItAndOnlyThose(): void
    {
        // Act
        ($this->handler)(new RemoveIngredientCommand('lait'));

        // Assert : l'alias de fusion qui désignait « lait » disparaît, ceux qui désignent autre chose (ou un autre utilisateur) restent
        $this->assertNull($this->aliasRepository->find('user-id', 'ancien-lait'));
        $this->assertNotNull($this->aliasRepository->find('user-id', 'beurre'));
        $this->assertNotNull($this->aliasRepository->find('other-id', 'beurre'));
    }

    public function testRemovingAnAdoptedCopyDeletesItsAliasAndDoesNotDecrementTheStatistics(): void
    {
        // Act
        ($this->handler)(new RemoveIngredientCommand('beurreuser-id'));

        // Assert : le commun « beurre » redevient visible pour l'utilisateur (plus d'alias), et une adoption n'est pas une création
        $this->assertNull($this->aliasRepository->find('user-id', 'beurre'));
        $this->assertNotNull($this->aliasRepository->find('other-id', 'beurre'));
        $this->assertSame([], $this->eventDispatcher->eventDispatched);
        $this->assertFalse($this->ingredientRepository->exists('beurreuser-id'));
    }

    public function testRemovingACommonIngredientTouchesNoAlias(): void
    {
        // Act
        ($this->handler)(new RemoveIngredientCommand('sucre'));

        // Assert
        $this->assertCount(3, $this->aliasRepository->all());
    }

    public function testRemovingAnIngredientForgetsTheDistinctPairsThatNameItAndOnlyThose(): void
    {
        // Act
        ($this->handler)(new RemoveIngredientCommand('lait'));

        // Assert : la paire « lait / beurre » disparaît ; l'autre paire de l'utilisateur et celle d'un autre utilisateur restent
        $this->assertSame(
            ['user-id|beurreuser-id|cremeuser-id', 'other-id|beurreuser-id|cremeuser-id'],
            array_map(static fn(IngredientDistinctPair $pair): string => $pair->getId(), $this->distinctRepository->all()),
        );
    }

    public function testRemovingAnAdoptedCopyForgetsItsDistinctPairsOfItsOwnerOnly(): void
    {
        // Act
        ($this->handler)(new RemoveIngredientCommand('beurreuser-id'));

        // Assert : les deux paires de l'utilisateur qui nomment la copie disparaissent, pas celle d'un autre utilisateur
        $this->assertSame(['other-id|beurreuser-id|cremeuser-id'], array_map(static fn(IngredientDistinctPair $pair): string => $pair->getId(), $this->distinctRepository->all()));
    }

    public function testRemovingACommonIngredientTouchesNoDistinctPair(): void
    {
        // Act
        ($this->handler)(new RemoveIngredientCommand('sucre'));

        // Assert
        $this->assertCount(3, $this->distinctRepository->all());
    }

    public function testFailedHandleRemoveIngredientUnknownIngredient(): void
    {
        // Arrange
        $command = new RemoveIngredientCommand('non-existant');

        // Assert
        $this->expectExceptionObject(IngredientException::notFound('non-existant'));

        // Act
        ($this->handler)($command);
    }

    public function testFailedHandleRemoveIngredientRecipeWithIngredientExist(): void
    {
        // Arrange
        $command = new RemoveIngredientCommand('pate');

        // Assert
        $this->expectExceptionObject(IngredientException::cannotBeRemoveExistInRecipe('pate'));

        // Act
        ($this->handler)($command);
    }

    public function testFailedHandleRemoveIngredientShoppingListWithIngredientExist(): void
    {
        // Arrange
        $command = new RemoveIngredientCommand('farine');

        // Assert
        $this->expectExceptionObject(IngredientException::cannotBeRemoveExistInShoppingList('farine'));

        // Act
        ($this->handler)($command);
    }
}
