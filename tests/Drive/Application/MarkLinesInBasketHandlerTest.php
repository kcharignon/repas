<?php

namespace Repas\Tests\Drive\Application;

use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Command\MarkLinesInBasket\MarkLinesInBasketCommand;
use Repas\Drive\Application\Command\MarkLinesInBasket\MarkLinesInBasketHandler;
use Repas\Repas\Application\Command\CheckRowsAddedToDriveBasket\CheckRowsAddedToDriveBasketCommand;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\ShoppingListRowBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListRowInMemoryRepository;
use Repas\Tests\Helper\RepasForDrive;

/**
 * Cocher ce que le drive a mis au panier (DRV-745) : le vrai handler du `Drive`, le vrai adapter et le vrai Open Host Service de `Repas`
 * (par transitivité, ADR 0035) sur les doubles en mémoire de `Repas`.
 */
class MarkLinesInBasketHandlerTest extends TestCase
{
    private ShoppingListInMemoryRepository $lists;
    private ShoppingListRowInMemoryRepository $rows;
    private RepasForDrive $repas;

    protected function setUp(): void
    {
        // Une liste envoyée de user-1 avec trois lignes (œuf, lait, sel dont le sel est déjà coché), une liste active, une liste d'un autre
        $owner = new UserBuilder()->withId('user-1');
        $this->lists = new ShoppingListInMemoryRepository([
            new ShoppingListBuilder()->withId('sent')->withOwner($owner)->withStatus(ShoppingListStatus::SENT)->build(),
            new ShoppingListBuilder()->withId('active')->withOwner($owner)->build(),
            new ShoppingListBuilder()->withId('theirs')->withOwner(new UserBuilder()->withId('user-2'))->withStatus(ShoppingListStatus::SENT)->build(),
        ]);
        $this->rows = new ShoppingListRowInMemoryRepository([
            new ShoppingListRowBuilder()->withId('egg')->withShoppingListId('sent')->withIngredient(new IngredientBuilder()->isEgg())->build(),
            new ShoppingListRowBuilder()->withId('milk')->withShoppingListId('sent')->withIngredient(new IngredientBuilder()->isMilk())->build(),
            new ShoppingListRowBuilder()->withId('salt')->withShoppingListId('sent')->withIngredient(new IngredientBuilder()->withSlug('sel'))->checked()->build(),
            new ShoppingListRowBuilder()->withId('active-egg')->withShoppingListId('active')->withIngredient(new IngredientBuilder()->isEgg())->build(),
            new ShoppingListRowBuilder()->withId('their-egg')->withShoppingListId('theirs')->withIngredient(new IngredientBuilder()->isEgg())->build(),
        ]);
        $this->repas = new RepasForDrive(aliases: ['ancien-lait' => 'lait']);
    }

    private function handler(?RepasForDrive $repas = null): MarkLinesInBasketHandler
    {
        $repas ??= $this->repas;

        return new MarkLinesInBasketHandler($repas->aliasPort(), $repas->basketLinePort($this->lists, $this->rows));
    }

    /**
     * @return array<string, bool> l'état coché de chaque ligne
     */
    private function checked(): array
    {
        $states = [];
        foreach (['egg', 'milk', 'salt', 'active-egg', 'their-egg'] as $id) {
            $states[$id] = $this->rows->findOneById($id)->isChecked();
        }

        return $states;
    }

    public function testChecksTheLinesOfTheIngredientsPutInTheBasketAndNothingElse(): void
    {
        // When
        ($this->handler())(new MarkLinesInBasketCommand('user-1', 'sent', ['oeuf']));

        // Then : seule la ligne de l'œuf de la liste envoyée est cochée (ni le lait, ni la liste active, ni celle d'un autre)
        $this->assertSame(['egg' => true, 'milk' => false, 'salt' => true, 'active-egg' => false, 'their-egg' => false], $this->checked());
    }

    public function testNeverUnchecksALineAndKeepsTheListSentAndItsVersion(): void
    {
        // Given : la liste envoyée en version 1
        $version = $this->lists->findOneById('sent')->getVersion();

        // When : le sel est déjà coché et n'est pas rapporté, puis tout le reste l'est : la dernière ligne cochée ne termine pas la liste
        ($this->handler())(new MarkLinesInBasketCommand('user-1', 'sent', ['oeuf', 'lait']));

        // Then
        $this->assertSame(['egg' => true, 'milk' => true, 'salt' => true, 'active-egg' => false, 'their-egg' => false], $this->checked());
        $list = $this->lists->findOneById('sent');
        $this->assertSame([ShoppingListStatus::SENT, $version], [$list->getStatus(), $list->getVersion()]);
        $this->assertTrue($this->rows->allRowsChecked('sent'));
    }

    public function testIsIdempotent(): void
    {
        // When : le même lot deux fois, puis un lot qui ne contient rien de nouveau
        ($this->handler())(new MarkLinesInBasketCommand('user-1', 'sent', ['oeuf']));
        ($this->handler())(new MarkLinesInBasketCommand('user-1', 'sent', ['oeuf']));
        ($this->handler())(new MarkLinesInBasketCommand('user-1', 'sent', ['sel']));

        // Then
        $this->assertSame(['egg' => true, 'milk' => false, 'salt' => true, 'active-egg' => false, 'their-egg' => false], $this->checked());
    }

    public function testAnIngredientThatIsNotInTheListIsIgnored(): void
    {
        // When
        ($this->handler())(new MarkLinesInBasketCommand('user-1', 'sent', ['farine', 'oeuf']));

        // Then : « farine » n'est pas dans la liste : aucune erreur, l'œuf est coché
        $this->assertSame(['egg' => true, 'milk' => false, 'salt' => true, 'active-egg' => false, 'their-egg' => false], $this->checked());
    }

    public function testAnOldSlugChecksTheLineOfTheCurrentIngredient(): void
    {
        // When : le client rapporte l'ancien slug du lait
        ($this->handler())(new MarkLinesInBasketCommand('user-1', 'sent', ['ancien-lait']));

        // Then : la ligne du lait est cochée, une seule traduction demandée pour tout le lot
        $this->assertSame(['egg' => false, 'milk' => true, 'salt' => true, 'active-egg' => false, 'their-egg' => false], $this->checked());
        $this->assertSame([['user-1', ['ancien-lait']]], $this->repas->aliases->resolveCalls);
        $this->assertEquals([new CheckRowsAddedToDriveBasketCommand('user-1', 'sent', ['lait'])], $this->repas->bus->dispatched());
    }

    public function testTheOldSlugAndTheCurrentOneOfTheSameIngredientAreSentOnce(): void
    {
        // When
        ($this->handler())(new MarkLinesInBasketCommand('user-1', 'sent', ['ancien-lait', 'lait']));

        // Then
        $this->assertEquals([new CheckRowsAddedToDriveBasketCommand('user-1', 'sent', ['lait'])], $this->repas->bus->dispatched());
    }

    public function testRefusesAListOfAnotherUserAndWritesNothing(): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListException::forbidden('theirs'));

        // When : même envoyée, la liste d'un autre n'est pas à user-1
        try {
            ($this->handler())(new MarkLinesInBasketCommand('user-1', 'theirs', ['oeuf']));
        } finally {
            $this->assertSame(['egg' => false, 'milk' => false, 'salt' => true, 'active-egg' => false, 'their-egg' => false], $this->checked());
        }
    }

    public function testRefusesAListThatIsNotSentAnymoreAndWritesNothing(): void
    {
        // Then : rouverte (ou terminée) entre-temps
        $this->expectExceptionObject(ShoppingListException::notSent('active'));

        // When
        try {
            ($this->handler())(new MarkLinesInBasketCommand('user-1', 'active', ['oeuf']));
        } finally {
            $this->assertSame(['egg' => false, 'milk' => false, 'salt' => true, 'active-egg' => false, 'their-egg' => false], $this->checked());
        }
    }

    public function testRefusesAnUnknownList(): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListException::shoppingListNotFound('inconnue'));

        // When
        ($this->handler())(new MarkLinesInBasketCommand('user-1', 'inconnue', ['oeuf']));
    }

    public function testAnOldSlugOfAnotherUserListIsStillRefused(): void
    {
        // Then : la traduction ne donne aucun droit sur la liste d'un autre
        $this->expectExceptionObject(ShoppingListException::forbidden('theirs'));

        // When
        ($this->handler())(new MarkLinesInBasketCommand('user-1', 'theirs', ['ancien-lait']));
    }
}
