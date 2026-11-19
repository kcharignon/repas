<?php

namespace Repas\Tests\Repas\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Model\DriveLineAttempt;
use Repas\Repas\Domain\Model\DriveLineOutcome;
use Repas\Repas\Domain\Model\RowCheckSource;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Tests\Helper\Builder\ShoppingListRowBuilder;

/**
 * Qui a coché une ligne et ce que le drive en a fait (ADR 0041) : les règles du modèle, sans base.
 */
class ShoppingListRowJournalTest extends TestCase
{
    public function testAFreshRowIsNotCheckedAndHasNoOrigin(): void
    {
        // When
        $row = new ShoppingListRowBuilder()->build();

        // Then
        $this->assertFalse($row->isChecked());
        $this->assertNull($row->getCheckedBy());
        $this->assertNull($row->getCheckedAt());
    }

    public function testTickingByHandRemembersItAndWhen(): void
    {
        // Given
        $row = new ShoppingListRowBuilder()->build();
        $at = new DateTimeImmutable('2026-11-19 10:30:00');

        // When
        $row->tick($at);

        // Then
        $this->assertTrue($row->isChecked());
        $this->assertSame(RowCheckSource::MANUAL, $row->getCheckedBy());
        $this->assertSame($at, $row->getCheckedAt());
    }

    public function testUncheckingForgetsTheOriginAndTheInstant(): void
    {
        // Given
        $row = new ShoppingListRowBuilder()->build();
        $row->tick(new DateTimeImmutable('2026-11-19 10:30:00'));

        // When
        $row->uncheck();

        // Then : l'origine s'efface avec la coche, jamais l'une sans l'autre
        $this->assertFalse($row->isChecked());
        $this->assertNull($row->getCheckedBy());
        $this->assertNull($row->getCheckedAt());
    }

    public function testALineCheckedByTheDriveKeepsItsOriginWhenThePersonTicksItAgain(): void
    {
        // Given : une ligne que le passage au drive a cochée
        $driveAt = new DateTimeImmutable('2026-11-19 10:00:00');
        $row = ShoppingListRow::load([
            'id' => 'row', 'shopping_list_id' => 'list', 'ingredient_slug' => 'lait', 'quantity' => 1.0, 'unit_slug' => 'litre', 'checked' => true,
            'checked_by' => RowCheckSource::DRIVE, 'checked_at' => $driveAt,
        ]);

        // When
        $row->tick(new DateTimeImmutable('2026-11-19 12:00:00'));

        // Then
        $this->assertSame([RowCheckSource::DRIVE, $driveAt], [$row->getCheckedBy(), $row->getCheckedAt()]);
    }

    public function testALineCheckedBeforeTheJournalHasNoOriginAndKeepsItWhenLoaded(): void
    {
        // When : une ligne cochée avant l'ADR 0041
        $row = ShoppingListRow::load(['id' => 'row', 'shopping_list_id' => 'list', 'ingredient_slug' => 'lait', 'quantity' => 1.0, 'unit_slug' => 'litre', 'checked' => true]);

        // Then : l'origine n'est pas devinée
        $this->assertTrue($row->isChecked());
        $this->assertNull($row->getCheckedBy());
    }

    public function testAnAttemptKeepsItsOutcomeAndItsReason(): void
    {
        // When
        $attempt = new DriveLineAttempt('lait', DriveLineOutcome::FAILED, 'BLOCKED');

        // Then
        $this->assertSame(['lait', DriveLineOutcome::FAILED, 'BLOCKED'], [$attempt->ingredientSlug, $attempt->outcome, $attempt->reason]);
        $this->assertNull(new DriveLineAttempt('lait', DriveLineOutcome::NOT_IN_CART)->reason);
    }

    #[DataProvider('invalidReasons')]
    public function testAReasonThatIsNotACodeIsRefused(string $reason): void
    {
        // Then : le rapport vient d'un site qu'on ne contrôle pas, jamais un texte libre
        $this->expectExceptionObject(ShoppingListException::invalidDriveReason());

        // When
        new DriveLineAttempt('lait', DriveLineOutcome::FAILED, $reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidReasons(): iterable
    {
        yield 'en minuscules' => ['blocked'];
        yield 'une phrase' => ['Le site a refusé'];
        yield 'avec un saut de ligne' => ["BLOCKED\n"];
        yield 'vide' => [''];
        yield 'trop longue' => ['A' . str_repeat('B', 40)];
        yield 'qui commence par un chiffre' => ['1BLOCKED'];
    }
}
