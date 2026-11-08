<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\PartShare;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListIngredient;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;

/**
 * Le registre caché des parties d'une liste (ADR 0038 décision 6) : sa forme gardée en base et sa lecture, qui est celle d'une entrée NON FIABLE
 * (comme `meal.placed_ingredients`) : tolérante, bornée, jamais d'exception.
 */
class PartShareTest extends TestCase
{
    public function testTheRegistryRoundTripsThroughItsStoredForm(): void
    {
        // Given
        $shares = [
            'jaune' => new PartShare('oeuf', 2.0, 2.0),
            'demi-gousse' => new PartShare('vanille', 3.0, 1.5),
        ];

        // When
        $stored = PartShare::registryToArray($shares);

        // Then : un objet JSON par slug de partie, et la même chose en retour
        $this->assertSame([
            'jaune' => ['parent' => 'oeuf', 'quantity' => 2.0, 'equivalent' => 2.0],
            'demi-gousse' => ['parent' => 'vanille', 'quantity' => 3.0, 'equivalent' => 1.5],
        ], $stored);
        $this->assertEquals($shares, PartShare::registryFrom($stored));
    }

    public function testAnEmptyRegistryIsStoredAsNothing(): void
    {
        $this->assertNull(PartShare::registryToArray([]));
    }

    public function testIntegersOfTheJsonAreNumbers(): void
    {
        // Given : jsonb rend `2` là où PHP a écrit `2.0`
        $stored = ['jaune' => ['parent' => 'oeuf', 'quantity' => 2, 'equivalent' => 3]];

        // When
        $shares = PartShare::registryFrom($stored);

        // Then
        $this->assertSame(2.0, $shares['jaune']->quantity);
        $this->assertSame(3.0, $shares['jaune']->equivalent);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unreliableRegistries(): iterable
    {
        yield 'rien' => [null];
        yield 'un texte' => ['{"jaune":1}'];
        yield 'un nombre' => [12];
        yield 'une liste au lieu d\'un objet' => [[['parent' => 'oeuf', 'quantity' => 1, 'equivalent' => 1]]];
        yield 'une entrée qui n\'est pas un objet' => [['jaune' => 3]];
        yield 'sans parent' => [['jaune' => ['quantity' => 1, 'equivalent' => 1]]];
        yield 'un parent vide' => [['jaune' => ['parent' => '', 'quantity' => 1, 'equivalent' => 1]]];
        yield 'un parent qui n\'est pas un texte' => [['jaune' => ['parent' => 5, 'quantity' => 1, 'equivalent' => 1]]];
        yield 'une quantité nulle' => [['jaune' => ['parent' => 'oeuf', 'quantity' => 0, 'equivalent' => 1]]];
        yield 'une quantité négative' => [['jaune' => ['parent' => 'oeuf', 'quantity' => -1, 'equivalent' => 1]]];
        yield 'un équivalent nul' => [['jaune' => ['parent' => 'oeuf', 'quantity' => 1, 'equivalent' => 0]]];
        yield 'une quantité en texte' => [['jaune' => ['parent' => 'oeuf', 'quantity' => '2', 'equivalent' => 1]]];
        yield 'un équivalent absent' => [['jaune' => ['parent' => 'oeuf', 'quantity' => 2]]];
        yield 'un nombre non fini' => [['jaune' => ['parent' => 'oeuf', 'quantity' => INF, 'equivalent' => 1]]];
        yield 'un nombre qui n\'en est pas un' => [['jaune' => ['parent' => 'oeuf', 'quantity' => NAN, 'equivalent' => 1]]];
        yield 'un slug de partie vide' => [['' => ['parent' => 'oeuf', 'quantity' => 1, 'equivalent' => 1]]];
    }

    #[DataProvider('unreliableRegistries')]
    public function testAnUnreliableRegistryIsReadWithoutAnyException(mixed $stored): void
    {
        $this->assertSame([], PartShare::registryFrom($stored));
    }

    public function testOnlyTheWellFormedEntriesAreKept(): void
    {
        // Given : une entrée juste entre deux qui n'ont pas de sens
        $stored = [
            'mauvaise' => ['parent' => 'oeuf', 'quantity' => -3, 'equivalent' => 1],
            'jaune' => ['parent' => 'oeuf', 'quantity' => 2, 'equivalent' => 2, 'inconnue' => 'ignorée'],
            'autre' => 'n\'importe quoi',
        ];

        // When
        $shares = PartShare::registryFrom($stored);

        // Then
        $this->assertSame(['jaune'], array_keys($shares));
        $this->assertEquals(new PartShare('oeuf', 2.0, 2.0), $shares['jaune']);
    }

    public function testTheRegistryIsBounded(): void
    {
        // Given : bien plus d'entrées qu'une liste n'en aura jamais
        $stored = [];
        for ($i = 0; $i < 5000; $i++) {
            $stored["partie-$i"] = ['parent' => 'oeuf', 'quantity' => 1, 'equivalent' => 1];
        }

        // When / Then
        $this->assertCount(1000, PartShare::registryFrom($stored));
    }

    public function testAListIsLoadedWithItsRegistryEvenWhenItIsGarbage(): void
    {
        // Given / When : un registre abîmé ne casse jamais le chargement d'une liste
        $list = ShoppingList::load([
            'id' => 'list-1',
            'owner_id' => 'owner-1',
            'created_at' => new \DateTimeImmutable(ShoppingListBuilder::DEFAULT_DATE),
            'status' => ShoppingListStatus::ACTIVE,
            'name' => null,
            'meals' => Tab::newEmptyTyped(Meal::class),
            'ingredients' => Tab::newEmptyTyped(ShoppingListIngredient::class),
            'rows' => Tab::newEmptyTyped(ShoppingListRow::class),
            'part_shares' => ['jaune' => ['parent' => 'oeuf', 'quantity' => 2, 'equivalent' => 2], 'abîmé' => 12],
        ]);

        // Then
        $this->assertEquals(['jaune' => new PartShare('oeuf', 2.0, 2.0)], $list->getPartShares());
    }
}
