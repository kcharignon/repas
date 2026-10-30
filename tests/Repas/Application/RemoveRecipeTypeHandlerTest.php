<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\RemoveRecipeType\RemoveRecipeTypeCommand;
use Repas\Repas\Application\Command\RemoveRecipeType\RemoveRecipeTypeHandler;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeTypeInMemoryRepository;

class RemoveRecipeTypeHandlerTest extends TestCase
{
    private RemoveRecipeTypeHandler $handler;
    private RecipeTypeInMemoryRepository $recipeTypeRepository;
    private RecipeInMemoryRepository $recipeRepository;

    protected function setUp(): void
    {
        $this->recipeTypeRepository = new RecipeTypeInMemoryRepository([
            new RecipeTypeBuilder()->isStarter()->build(),
            new RecipeTypeBuilder()->isMeal()->build(),
        ]);
        $this->recipeRepository = new RecipeInMemoryRepository();
        $this->handler = new RemoveRecipeTypeHandler($this->recipeTypeRepository, $this->recipeRepository);
    }

    public function testItMovesTheRecipesToTheTargetTypeThenRemovesTheType(): void
    {
        // Given
        $command = new RemoveRecipeTypeCommand('entrée', 'plat');

        // When
        ($this->handler)($command);

        // Then
        $this->assertSame([['entrée', 'plat']], $this->recipeRepository->movedToType);
        $this->assertFalse($this->recipeTypeRepository->exists('entrée'));
        $this->assertTrue($this->recipeTypeRepository->exists('plat'));
    }

    public function testAnUnknownTypeIsNotFoundAndNothingIsMoved(): void
    {
        // Then
        $this->expectExceptionObject(RecipeException::typeNotFound('inconnu'));

        // When
        try {
            ($this->handler)(new RemoveRecipeTypeCommand('inconnu', 'plat'));
        } finally {
            $this->assertSame([], $this->recipeRepository->movedToType);
            $this->assertTrue($this->recipeTypeRepository->exists('plat'));
        }
    }

    public function testAnUnknownTargetTypeIsNotFoundAndNothingIsMovedNorRemoved(): void
    {
        // Then
        $this->expectExceptionObject(RecipeException::typeNotFound('inconnu'));

        // When
        try {
            ($this->handler)(new RemoveRecipeTypeCommand('entrée', 'inconnu'));
        } finally {
            $this->assertSame([], $this->recipeRepository->movedToType);
            $this->assertTrue($this->recipeTypeRepository->exists('entrée'));
        }
    }

    public function testATypeCannotBeTransferredToItself(): void
    {
        // Then
        $this->expectExceptionObject(RecipeException::typeTransferToItself());

        // When
        try {
            ($this->handler)(new RemoveRecipeTypeCommand('plat', 'plat'));
        } finally {
            $this->assertSame([], $this->recipeRepository->movedToType);
            $this->assertTrue($this->recipeTypeRepository->exists('plat'));
        }
    }
}
