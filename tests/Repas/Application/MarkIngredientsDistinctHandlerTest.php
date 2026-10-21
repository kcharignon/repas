<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\MarkIngredientsDistinct\MarkIngredientsDistinctCommand;
use Repas\Repas\Application\Command\MarkIngredientsDistinct\MarkIngredientsDistinctHandler;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientDistinctPair;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\IngredientDistinctInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;

/**
 * « Pas le même » (ING-006) : la paire est mémorisée dans l'ordre des slugs, rien d'autre n'est écrit. Les deux ingrédients doivent être à
 * l'utilisateur, sans exception pour un administrateur.
 */
class MarkIngredientsDistinctHandlerTest extends TestCase
{
    private const string USER = 'user-id';
    private const string OTHER = 'other-id';

    private IngredientInMemoryRepository $ingredients;
    private IngredientDistinctInMemoryRepository $distinct;
    private MarkIngredientsDistinctHandler $handler;

    protected function setUp(): void
    {
        $this->ingredients = new IngredientInMemoryRepository([
            self::ingredient('Tomate', self::USER),
            self::ingredient('Tomates', self::USER),
            self::ingredient('Courgette', self::USER),
            self::ingredient('Tomate', self::OTHER),
            self::ingredient('Tomate', null),
        ]);
        $this->distinct = new IngredientDistinctInMemoryRepository();
        $this->handler = new MarkIngredientsDistinctHandler($this->ingredients, $this->distinct, new FrozenClock(new DateTimeImmutable('2026-10-21 08:00:00')));
    }

    private static function ingredient(string $name, ?string $creator): Ingredient
    {
        $builder = new IngredientBuilder()
            ->withName($name)
            ->withDepartment(new DepartmentBuilder()->isCereal())
            ->withDefaultCookingUnit(new UnitBuilder()->isGramme())
            ->withDefaultPurchaseUnit(new UnitBuilder()->isKilo());

        // `null` : la forme de la base d'avant la passation, un ingrédient sans propriétaire, qui n'est plus traité à part (il n'est à personne)
        return ($creator === null ? $builder->withoutCreator() : $builder->withCreator(new UserBuilder()->withId($creator)->build(), true))->build();
    }

    private function mark(string $slug, string $other, string $userId = self::USER): void
    {
        ($this->handler)(new MarkIngredientsDistinctCommand($userId, $slug, $other));
    }

    public function testThePairIsRememberedInTheOrderOfTheSlugsWhateverTheOrderOfTheCall(): void
    {
        // When
        $this->mark('tomatesuser-id', 'tomateuser-id');

        // Then
        $pairs = $this->distinct->all();
        $this->assertCount(1, $pairs);
        $this->assertSame([self::USER, 'tomatesuser-id', 'tomateuser-id'], [$pairs[0]->getUserId(), $pairs[0]->getFirstSlug(), $pairs[0]->getSecondSlug()]);
        $this->assertEquals(new DateTimeImmutable('2026-10-21 08:00:00'), $pairs[0]->getCreatedAt());
    }

    public function testItIsIdempotentInEitherOrderAndKeepsTheFirstDate(): void
    {
        // Given
        $this->mark('tomateuser-id', 'tomatesuser-id');
        $this->handler = new MarkIngredientsDistinctHandler($this->ingredients, $this->distinct, new FrozenClock(new DateTimeImmutable('2026-12-01 08:00:00')));

        // When
        $this->mark('tomateuser-id', 'tomatesuser-id');
        $this->mark('tomatesuser-id', 'tomateuser-id');

        // Then
        $this->assertCount(1, $this->distinct->all());
        $this->assertEquals(new DateTimeImmutable('2026-10-21 08:00:00'), $this->distinct->all()[0]->getCreatedAt());
    }

    public function testTwoDifferentPairsAreTwoLines(): void
    {
        // When
        $this->mark('tomateuser-id', 'tomatesuser-id');
        $this->mark('tomateuser-id', 'courgetteuser-id');

        // Then
        $this->assertCount(2, $this->distinct->all());
    }

    public function testNothingButTheMemoryOfThePairIsWritten(): void
    {
        // Given
        $before = $this->ingredients->findAll()->count();

        // When
        $this->mark('tomateuser-id', 'tomatesuser-id');

        // Then
        $this->assertSame($before, $this->ingredients->findAll()->count());
        $this->assertTrue($this->ingredients->exists('tomateuser-id'));
        $this->assertTrue($this->ingredients->exists('tomatesuser-id'));
    }

    public function testAnIngredientIsNotDistinctFromItself(): void
    {
        // Then
        $this->expectExceptionObject(IngredientException::cannotBeDistinctFromItself());

        // When
        $this->mark('tomateuser-id', 'tomateuser-id');
    }

    public function testTheIngredientOfAnotherUserIsUnknownOnEitherSide(): void
    {
        foreach ([['tomateuser-id', 'tomateother-id'], ['tomateother-id', 'tomateuser-id']] as [$slug, $other]) {
            try {
                // When
                $this->mark($slug, $other);
                $this->fail('Le refus attendu n\'a pas eu lieu.');
            } catch (IngredientException $exception) {
                // Then : inconnu, comme un slug qui n'existe pas : on ne révèle rien de l'ingrédient d'un autre
                $this->assertSame(404, $exception->getCode());
            }
        }
        $this->assertSame([], $this->distinct->all());
    }

    public function testAnIngredientWithoutAnOwnerIsNotTheUsers(): void
    {
        foreach ([['tomateuser-id', 'tomate'], ['tomate', 'tomateuser-id']] as [$slug, $other]) {
            try {
                $this->mark($slug, $other);
                $this->fail('Le refus attendu n\'a pas eu lieu.');
            } catch (IngredientException $exception) {
                $this->assertSame(404, $exception->getCode());
            }
        }
        $this->assertSame([], $this->distinct->all());
    }

    public function testAnUnknownIngredientIsRefusedWith404(): void
    {
        // Then
        $this->expectExceptionObject(IngredientException::notFound('inconnu'));

        // When
        $this->mark('tomateuser-id', 'inconnu');
    }

    public function testAnAdministratorHasNoWayIn(): void
    {
        // Given : le cas d'usage ne reçoit que l'identifiant de l'utilisateur du jeton, sans rôle : l'administrateur ne peut pas écarter la paire d'un autre
        try {
            // When
            $this->mark('tomateuser-id', 'tomatesuser-id', 'admin-id');
            $this->fail('Le refus attendu n\'a pas eu lieu.');
        } catch (IngredientException $exception) {
            // Then
            $this->assertSame(404, $exception->getCode());
        }
        $this->assertSame([], $this->distinct->all());
    }

    public function testTheMemoryOfAUserIsNotTheMemoryOfAnother(): void
    {
        // When
        $this->mark('tomateuser-id', 'tomatesuser-id');

        // Then
        $this->assertSame([self::USER . '|tomatesuser-id|tomateuser-id'], array_map(static fn(IngredientDistinctPair $pair): string => $pair->getId(), $this->distinct->all()));
    }
}
