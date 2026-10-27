<?php

namespace Repas\Tests\Repas\Application\Query\GetConversionHealth;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\GetConversionHealth\ConversionHealthAccountDTO;
use Repas\Repas\Application\Query\GetConversionHealth\ConversionHealthDTO;
use Repas\Repas\Application\Query\GetConversionHealth\GetConversionHealthQuery;
use Repas\Repas\Application\Query\GetConversionHealth\GetConversionHealthQueryHandler;
use Repas\Repas\Application\Query\GetConversionHealth\ReferenceConversionDTO;
use Repas\Repas\Application\Query\GetConversionHealth\RepeatedUniversalConversionDTO;
use Repas\Tests\Helper\InMemoryLoader\ConversionHealthInMemoryLoader;

class GetConversionHealthQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturnsAndReadsOnce(): void
    {
        // Given
        $report = $this->report();
        $loader = new ConversionHealthInMemoryLoader($report);

        // When
        $result = new GetConversionHealthQueryHandler($loader)(new GetConversionHealthQuery());

        // Then
        $this->assertSame($report, $result);
        $this->assertSame(1, $loader->reportCalls);
    }

    public function testTheVerdictIsGreenWhenNothingIsMissingAndEveryIngredientConverts(): void
    {
        // Given : tout est là ; un autre coefficient, une universelle en plus, une conversion détournée et des lignes de recettes non convertibles se disent seulement
        $report = $this->report(
            reference: [new ReferenceConversionDTO('kilo > gramme x 1000', ReferenceConversionDTO::OTHER_COEFFICIENT, 'kilo > gramme x 900')],
            extra: ['flacon > bouteille x 1'],
            repeated: [new RepeatedUniversalConversionDTO('kilo > gramme x 1000', 3, 1)],
            accounts: [$this->account(1, ingredientsNotConvertible: 0, rowsNotConvertible: 4)],
        );

        // Then
        $this->assertTrue($report->isHealthy());
        $this->assertSame([0, 1, 1, 3, 1, 0, 4], [$report->missingReference(), $report->otherCoefficientReference(), $report->presentReference(), $report->repeatedCount(), $report->hijackedCount(), $report->ingredientsNotConvertible(), $report->recipeRowsNotConvertible()]);
    }

    public function testTheVerdictIsToLookAtWhenAReferenceLineIsMissing(): void
    {
        // Given
        $report = $this->report(reference: [
            new ReferenceConversionDTO('litre > millilitre x 1000', ReferenceConversionDTO::PRESENT, 'litre > millilitre x 1000'),
            new ReferenceConversionDTO('centilitre > millilitre x 10', ReferenceConversionDTO::MISSING),
        ]);

        // Then
        $this->assertFalse($report->isHealthy());
        $this->assertSame([1, 1], [$report->missingReference(), $report->presentReference()]);
    }

    public function testTheVerdictIsToLookAtWhenAnIngredientDoesNotConvertAnAccountOrNone(): void
    {
        // Given : un ingrédient non convertible chez un compte, puis un autre sans compte existant
        $withAccount = $this->report(accounts: [$this->account(1), $this->account(2, ingredientsNotConvertible: 2)]);
        $withoutAccount = $this->report(accounts: [$this->account(1)], unattachedIngredients: 1, unattachedRows: 3);

        // Then
        $this->assertFalse($withAccount->isHealthy());
        $this->assertSame(2, $withAccount->ingredientsNotConvertible());
        $this->assertFalse($withoutAccount->isHealthy());
        $this->assertSame([1, 3], [$withoutAccount->ingredientsNotConvertible(), $withoutAccount->recipeRowsNotConvertible()]);
    }

    /**
     * @param list<ReferenceConversionDTO>|null $reference
     * @param list<string> $extra
     * @param list<RepeatedUniversalConversionDTO> $repeated
     * @param list<ConversionHealthAccountDTO> $accounts
     */
    private function report(?array $reference = null, array $extra = [], array $repeated = [], array $accounts = [], int $unattachedIngredients = 0, int $unattachedRows = 0): ConversionHealthDTO
    {
        return new ConversionHealthDTO(
            $reference ?? [new ReferenceConversionDTO('kilo > gramme x 1000', ReferenceConversionDTO::PRESENT, 'kilo > gramme x 1000')],
            $extra,
            $repeated,
            $accounts,
            $unattachedIngredients,
            $unattachedRows,
        );
    }

    private function account(int $rank, int $ingredientsNotConvertible = 0, int $rowsNotConvertible = 0): ConversionHealthAccountDTO
    {
        return new ConversionHealthAccountDTO($rank, 'abcd1234', 'ACTIVE', 10, $ingredientsNotConvertible, 2, 12, $rowsNotConvertible);
    }
}
