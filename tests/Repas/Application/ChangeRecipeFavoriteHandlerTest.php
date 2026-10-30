<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\ChangeRecipeFavorite\ChangeRecipeFavoriteCommand;
use Repas\Repas\Application\Command\ChangeRecipeFavorite\ChangeRecipeFavoriteHandler;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;

class ChangeRecipeFavoriteHandlerTest extends TestCase
{
    private RecipeInMemoryRepository $recipeRepository;
    private ChangeRecipeFavoriteHandler $handler;

    protected function setUp(): void
    {
        $this->recipeRepository = new RecipeInMemoryRepository([
            new RecipeBuilder()->withId('recipe-1')->withAuthorId('author-id')->build(),
            new RecipeBuilder()->withId('recipe-2')->withAuthorId('author-id')->asFavorite()->build(),
            new RecipeBuilder()->withId('recipe-3')->withAuthorId('author-id')->asSecret()->build(),
            new RecipeBuilder()->withId('recipe-4')->withAuthorId('author-id')->withVersion(3)->build(),
        ]);
        $this->handler = new ChangeRecipeFavoriteHandler($this->recipeRepository);
    }

    public function testTheAuthorPutsHisRecipeInFavorites(): void
    {
        // When
        ($this->handler)(new ChangeRecipeFavoriteCommand('recipe-1', 'author-id', true));

        // Then
        $this->assertTrue($this->recipeRepository->findOneById('recipe-1')->isFavorite());
    }

    public function testTheAuthorTakesHisRecipeOutOfFavorites(): void
    {
        // When
        ($this->handler)(new ChangeRecipeFavoriteCommand('recipe-2', 'author-id', false));

        // Then
        $this->assertFalse($this->recipeRepository->findOneById('recipe-2')->isFavorite());
    }

    public function testTheAuthorCanFavoriteASecretRecipeAndItStaysSecret(): void
    {
        // When
        ($this->handler)(new ChangeRecipeFavoriteCommand('recipe-3', 'author-id', true));

        // Then
        $recipe = $this->recipeRepository->findOneById('recipe-3');
        $this->assertTrue($recipe->isFavorite());
        $this->assertTrue($recipe->isSecret());
    }

    public function testAnotherUserCannotFavoriteTheRecipeOfSomeoneElse(): void
    {
        // Then
        $this->expectExceptionObject(RecipeException::favoriteForbidden());

        // When
        try {
            ($this->handler)(new ChangeRecipeFavoriteCommand('recipe-1', 'admin-id', true));
        } finally {
            $this->assertFalse($this->recipeRepository->findOneById('recipe-1')->isFavorite());
        }
    }

    public function testTheSecretRecipeOfSomeoneElseDoesNotExistForTheUser(): void
    {
        // Then : le même refus qu'une recette inconnue, jamais un 403
        $this->expectExceptionObject(RecipeException::notFound('recipe-3'));

        // When
        ($this->handler)(new ChangeRecipeFavoriteCommand('recipe-3', 'admin-id', true));
    }

    public function testAnUnknownRecipeIsNotFound(): void
    {
        // Then
        $this->expectExceptionObject(RecipeException::notFound('inconnue'));

        // When
        ($this->handler)(new ChangeRecipeFavoriteCommand('inconnue', 'author-id', true));
    }

    public function testAnOutdatedVersionIsRefusedAndNothingIsChanged(): void
    {
        // Then
        $this->expectExceptionObject(RecipeException::outdated('recipe-4'));

        // When
        try {
            ($this->handler)(new ChangeRecipeFavoriteCommand('recipe-4', 'author-id', true, expectedVersion: 2));
        } finally {
            $this->assertFalse($this->recipeRepository->findOneById('recipe-4')->isFavorite());
        }
    }

    public function testTheExpectedVersionIsAcceptedWhenItIsTheCurrentOne(): void
    {
        // When
        ($this->handler)(new ChangeRecipeFavoriteCommand('recipe-4', 'author-id', true, expectedVersion: 3));

        // Then
        $this->assertTrue($this->recipeRepository->findOneById('recipe-4')->isFavorite());
    }
}
