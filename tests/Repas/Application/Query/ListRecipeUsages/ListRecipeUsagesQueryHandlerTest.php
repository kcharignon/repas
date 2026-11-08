<?php

namespace Repas\Tests\Repas\Application\Query\ListRecipeUsages;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeSummaryDTO;
use Repas\Repas\Application\Query\ListRecipeUsages\ListRecipeUsagesQuery;
use Repas\Repas\Application\Query\ListRecipeUsages\ListRecipeUsagesQueryHandler;
use Repas\Repas\Application\Query\ListRecipeUsages\RecipeUsagesDTO;
use Repas\Tests\Helper\InMemoryLoader\RecipeInMemoryLoader;

class ListRecipeUsagesQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturnsAndForwardsTheRecipeAndTheReader(): void
    {
        // Given
        $usages = new RecipeUsagesDTO([new RecipeSummaryDTO('pie', 'Tarte', false)], 1);
        $loader = new RecipeInMemoryLoader(usages: $usages);
        $handler = new ListRecipeUsagesQueryHandler($loader);

        // When
        $result = $handler(new ListRecipeUsagesQuery('dough', 'user-1'));

        // Then
        $this->assertSame($usages, $result);
        $this->assertSame([['dough', 'user-1']], $loader->listUsagesCalls);
    }
}
