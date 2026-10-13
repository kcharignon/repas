<?php

namespace Repas\Tests\Repas\Application\Query\GetActiveShoppingListSummary;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\GetActiveShoppingListSummary\GetActiveShoppingListSummaryQuery;
use Repas\Repas\Application\Query\GetActiveShoppingListSummary\GetActiveShoppingListSummaryQueryHandler;
use Repas\Tests\Helper\Builder\DTO\ActiveShoppingListSummaryDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\ShoppingListInMemoryLoader;

class GetActiveShoppingListSummaryQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $summary = new ActiveShoppingListSummaryDTOBuilder()->withQuantity('oeuf', 6)->withPlannedRecipe('recipe-1')->build();
        $handler = new GetActiveShoppingListSummaryQueryHandler(new ShoppingListInMemoryLoader(activeSummary: $summary));

        // When
        $result = $handler(new GetActiveShoppingListSummaryQuery('owner-1'));

        // Then
        $this->assertSame($summary, $result);
    }

    public function testForwardsTheOwnerToTheLoader(): void
    {
        // Given
        $loader = new ShoppingListInMemoryLoader();
        $handler = new GetActiveShoppingListSummaryQueryHandler($loader);

        // When
        $handler(new GetActiveShoppingListSummaryQuery('owner-1'));

        // Then
        $this->assertSame(['owner-1'], $loader->getActiveSummaryCalls);
    }
}
