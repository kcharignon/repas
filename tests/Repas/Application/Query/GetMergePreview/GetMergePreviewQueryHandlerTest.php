<?php

namespace Repas\Tests\Repas\Application\Query\GetMergePreview;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\GetMergePreview\GetMergePreviewQuery;
use Repas\Repas\Application\Query\GetMergePreview\GetMergePreviewQueryHandler;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Tests\Helper\Builder\DTO\MergePreviewDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\IngredientMergeInMemoryLoader;

class GetMergePreviewQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $preview = new MergePreviewDTOBuilder()->build();
        $handler = new GetMergePreviewQueryHandler(new IngredientMergeInMemoryLoader(preview: $preview));

        // When
        $result = $handler(new GetMergePreviewQuery('user-1', 'tomates', 'tomate'));

        // Then
        $this->assertSame($preview, $result);
    }

    public function testForwardsTheUserAndTheTwoIngredientsToTheLoader(): void
    {
        // Given
        $loader = new IngredientMergeInMemoryLoader(preview: new MergePreviewDTOBuilder()->build());
        $handler = new GetMergePreviewQueryHandler($loader);

        // When
        $handler(new GetMergePreviewQuery('user-1', 'tomates', 'tomate'));

        // Then : l'absorbé d'abord, le gardé ensuite
        $this->assertSame([['user-1', 'tomates', 'tomate']], $loader->previewCalls);
    }

    public function testAnUnknownIngredientFailsLikeTheLoader(): void
    {
        // Given
        $handler = new GetMergePreviewQueryHandler(new IngredientMergeInMemoryLoader());

        // Then
        $this->expectExceptionObject(IngredientException::notFound('tomates'));

        // When
        $handler(new GetMergePreviewQuery('user-1', 'tomates', 'tomate'));
    }
}
