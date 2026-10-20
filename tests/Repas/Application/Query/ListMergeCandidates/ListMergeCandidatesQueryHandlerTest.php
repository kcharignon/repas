<?php

namespace Repas\Tests\Repas\Application\Query\ListMergeCandidates;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListMergeCandidates\ListMergeCandidatesQuery;
use Repas\Repas\Application\Query\ListMergeCandidates\ListMergeCandidatesQueryHandler;
use Repas\Repas\Application\Query\ListMergeCandidates\MergeCandidateDTO;
use Repas\Tests\Helper\InMemoryLoader\IngredientMergeInMemoryLoader;

class ListMergeCandidatesQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $candidates = [new MergeCandidateDTO('tomate', 'Tomate', 'fruit-et-legume', 'Fruit & légume', 'kilo', 12, 3, true, true)];
        $handler = new ListMergeCandidatesQueryHandler(new IngredientMergeInMemoryLoader(candidates: $candidates));

        // When
        $result = $handler(new ListMergeCandidatesQuery('user-1', 'tomates'));

        // Then
        $this->assertSame($candidates, $result);
    }

    public function testForwardsTheUserTheIngredientAndTheSearchToTheLoader(): void
    {
        // Given
        $loader = new IngredientMergeInMemoryLoader();
        $handler = new ListMergeCandidatesQueryHandler($loader);

        // When
        $handler(new ListMergeCandidatesQuery('user-1', 'tomates', 'tom'));
        $handler(new ListMergeCandidatesQuery('user-1', 'tomates'));

        // Then
        $this->assertSame([['user-1', 'tomates', 'tom'], ['user-1', 'tomates', null]], $loader->candidatesCalls);
    }
}
