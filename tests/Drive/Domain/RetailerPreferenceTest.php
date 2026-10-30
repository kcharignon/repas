<?php

namespace Repas\Tests\Drive\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\RetailerPreference;

class RetailerPreferenceTest extends TestCase
{
    private const string USER = 'user-1';

    public function testANewPreferenceHasNeitherADefaultNorALastRetailer(): void
    {
        // When
        $preference = RetailerPreference::create(self::USER, new DateTimeImmutable('2026-10-30 10:00:00'));

        // Then
        $this->assertSame([self::USER, null, null], [$preference->getUserId(), $preference->getDefaultRetailer(), $preference->getLastUsedRetailer()]);
        $this->assertSame(self::USER, $preference->getId());
        $this->assertFalse($preference->refersTo(Retailer::LECLERC));
    }

    public function testTheDefaultAndTheLastRetailerAreSetIndependently(): void
    {
        // Given
        $created = new DateTimeImmutable('2026-10-30 10:00:00');
        $later = new DateTimeImmutable('2026-10-30 11:00:00');
        $preference = RetailerPreference::create(self::USER, $created);

        // When
        $preference->chooseDefault(Retailer::LECLERC, $later);
        $preference->rememberLastUsed(Retailer::AUCHAN, $later);

        // Then
        $this->assertSame([Retailer::LECLERC, Retailer::AUCHAN, $later], [$preference->getDefaultRetailer(), $preference->getLastUsedRetailer(), $preference->getUpdatedAt()]);
        $this->assertTrue($preference->refersTo(Retailer::LECLERC));
        $this->assertTrue($preference->refersTo(Retailer::AUCHAN));
    }

    public function testChoosingNullClearsTheDefaultAndLeavesTheLastRetailer(): void
    {
        // Given
        $preference = RetailerPreference::create(self::USER, new DateTimeImmutable('2026-10-30 10:00:00'))
            ->chooseDefault(Retailer::LECLERC, new DateTimeImmutable('2026-10-30 10:00:00'))
            ->rememberLastUsed(Retailer::AUCHAN, new DateTimeImmutable('2026-10-30 10:00:00'));

        // When
        $preference->chooseDefault(null, new DateTimeImmutable('2026-10-30 12:00:00'));

        // Then
        $this->assertSame([null, Retailer::AUCHAN], [$preference->getDefaultRetailer(), $preference->getLastUsedRetailer()]);
    }

    public function testAChangeThatChangesNothingKeepsTheDate(): void
    {
        // Given
        $created = new DateTimeImmutable('2026-10-30 10:00:00');
        $preference = RetailerPreference::create(self::USER, $created)->chooseDefault(Retailer::LECLERC, $created)->rememberLastUsed(Retailer::LECLERC, $created);

        // When
        $preference->chooseDefault(Retailer::LECLERC, new DateTimeImmutable('2026-10-31 10:00:00'))->rememberLastUsed(Retailer::LECLERC, new DateTimeImmutable('2026-10-31 10:00:00'));

        // Then
        $this->assertSame($created, $preference->getUpdatedAt());
    }

    public function testForgettingARetailerClearsWhatPointsToItAndNothingElse(): void
    {
        // Given : le drive habituel est A, la dernière enseigne B
        $created = new DateTimeImmutable('2026-10-30 10:00:00');
        $preference = RetailerPreference::create(self::USER, $created)->chooseDefault(Retailer::LECLERC, $created)->rememberLastUsed(Retailer::AUCHAN, $created);
        $later = new DateTimeImmutable('2026-10-30 12:00:00');

        // When
        $preference->forget(Retailer::LECLERC, $later);

        // Then : le drive habituel disparaît, la dernière enseigne reste
        $this->assertSame([null, Retailer::AUCHAN, $later], [$preference->getDefaultRetailer(), $preference->getLastUsedRetailer(), $preference->getUpdatedAt()]);
    }

    public function testForgettingARetailerThatIsBothTheDefaultAndTheLastClearsBoth(): void
    {
        // Given
        $created = new DateTimeImmutable('2026-10-30 10:00:00');
        $preference = RetailerPreference::create(self::USER, $created)->chooseDefault(Retailer::AUCHAN, $created)->rememberLastUsed(Retailer::AUCHAN, $created);

        // When
        $preference->forget(Retailer::AUCHAN, new DateTimeImmutable('2026-10-30 12:00:00'));

        // Then
        $this->assertSame([null, null], [$preference->getDefaultRetailer(), $preference->getLastUsedRetailer()]);
        $this->assertFalse($preference->refersTo(Retailer::AUCHAN));
    }

    public function testForgettingAnUnrelatedRetailerChangesNothing(): void
    {
        // Given
        $created = new DateTimeImmutable('2026-10-30 10:00:00');
        $preference = RetailerPreference::create(self::USER, $created)->chooseDefault(Retailer::LECLERC, $created);

        // When
        $preference->forget(Retailer::AUCHAN, new DateTimeImmutable('2026-10-30 12:00:00'));

        // Then
        $this->assertSame([Retailer::LECLERC, null, $created], [$preference->getDefaultRetailer(), $preference->getLastUsedRetailer(), $preference->getUpdatedAt()]);
    }
}
