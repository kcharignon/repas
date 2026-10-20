<?php

namespace Repas\Tests\Repas\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\IngredientDistinctPair;

class IngredientDistinctPairTest extends TestCase
{
    public function testAPairHasNoOrderTheTwoSlugsAreSortedByBytes(): void
    {
        // Given
        $now = new DateTimeImmutable('2026-10-21 10:00:00');

        // When
        $forward = IngredientDistinctPair::create('user-id', 'tomate-u', 'tomates-u', $now);
        $backward = IngredientDistinctPair::create('user-id', 'tomates-u', 'tomate-u', $now);

        // Then : le tiret (0x2D) précède les lettres : l'ordre des octets, pas celui d'une collation de langue
        $this->assertSame(['tomate-u', 'tomates-u'], [$forward->getFirstSlug(), $forward->getSecondSlug()]);
        $this->assertTrue($forward->isEqual($backward));
        $this->assertSame('user-id|tomate-u|tomates-u', $backward->getId());
        $this->assertSame(IngredientDistinctPair::keyOf('tomates-u', 'tomate-u'), IngredientDistinctPair::keyOf('tomate-u', 'tomates-u'));
        $this->assertSame($forward->key(), IngredientDistinctPair::keyOf('tomates-u', 'tomate-u'));
        $this->assertSame($now, $backward->getCreatedAt());
    }

    public function testAnIngredientIsNotDistinctFromItself(): void
    {
        // Then
        $this->expectExceptionObject(IngredientException::cannotBeDistinctFromItself());

        // When
        IngredientDistinctPair::create('user-id', 'tomate-u', 'tomate-u', new DateTimeImmutable());
    }

    public function testItNamesItsTwoIngredientsOnly(): void
    {
        // Given
        $pair = IngredientDistinctPair::create('user-id', 'tomate-u', 'tomates-u', new DateTimeImmutable());

        // Then
        $this->assertTrue($pair->names('tomate-u'));
        $this->assertTrue($pair->names('tomates-u'));
        $this->assertFalse($pair->names('tomate'));
    }
}
