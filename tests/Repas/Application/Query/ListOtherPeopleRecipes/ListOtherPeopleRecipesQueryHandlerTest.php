<?php

namespace Repas\Tests\Repas\Application\Query\ListOtherPeopleRecipes;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\ListOtherPeopleRecipesQuery;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\ListOtherPeopleRecipesQueryHandler;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipeDTO;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipesDTO;
use Repas\Tests\Helper\InMemoryLoader\RecipeInMemoryLoader;

class ListOtherPeopleRecipesQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $recipes = new OtherPeopleRecipesDTO([new OtherPeopleRecipeDTO('recipe-1', 'omelette', 'plat', 'plat')], 1);
        $handler = new ListOtherPeopleRecipesQueryHandler(new RecipeInMemoryLoader(otherPeopleRecipes: $recipes));

        // When
        $result = $handler(new ListOtherPeopleRecipesQuery('user-1'));

        // Then
        $this->assertSame($recipes, $result);
    }

    public function testForwardsTheUserAndTheDefaultFirstPageOfTwoHundredToTheLoader(): void
    {
        // Given
        $loader = new RecipeInMemoryLoader();
        $handler = new ListOtherPeopleRecipesQueryHandler($loader);

        // When
        $handler(new ListOtherPeopleRecipesQuery('user-1'));

        // Then
        $this->assertSame([['user-1', 200, 0, null]], $loader->listOfOtherPeopleCalls);
    }

    public function testForwardsTheRequestedPageToTheLoader(): void
    {
        // Given
        $loader = new RecipeInMemoryLoader();
        $handler = new ListOtherPeopleRecipesQueryHandler($loader);

        // When
        $handler(new ListOtherPeopleRecipesQuery('user-1', 50, 100));

        // Then
        $this->assertSame([['user-1', 50, 100, null]], $loader->listOfOtherPeopleCalls);
    }

    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function outOfBoundsPages(): iterable
    {
        yield 'plus de deux cents' => [5000, 0, 200, 0];
        yield 'zéro recette' => [0, 0, 1, 0];
        yield 'négatif' => [-3, -7, 1, 0];
    }

    #[DataProvider('outOfBoundsPages')]
    public function testTheRequestedPageIsBoundedBeforeItReachesTheLoader(int $limit, int $offset, int $expectedLimit, int $expectedOffset): void
    {
        // Given
        $loader = new RecipeInMemoryLoader();
        $handler = new ListOtherPeopleRecipesQueryHandler($loader);

        // When
        $handler(new ListOtherPeopleRecipesQuery('user-1', $limit, $offset));

        // Then
        $this->assertSame([['user-1', $expectedLimit, $expectedOffset, null]], $loader->listOfOtherPeopleCalls);
    }

    public function testForwardsTheSearchTextToTheLoaderAsIs(): void
    {
        // Given
        $loader = new RecipeInMemoryLoader();
        $handler = new ListOtherPeopleRecipesQueryHandler($loader);

        // When
        $handler(new ListOtherPeopleRecipesQuery('user-1', 50, 10, ' Pâte Carbo '));

        // Then
        $this->assertSame([['user-1', 50, 10, ' Pâte Carbo ']], $loader->listOfOtherPeopleCalls);
    }

    #[DataProvider('blankSearches')]
    public function testABlankSearchIsNoSearch(?string $search): void
    {
        // Given
        $loader = new RecipeInMemoryLoader();
        $handler = new ListOtherPeopleRecipesQueryHandler($loader);

        // When
        $handler(new ListOtherPeopleRecipesQuery('user-1', 200, 0, $search));

        // Then
        $this->assertSame([['user-1', 200, 0, null]], $loader->listOfOtherPeopleCalls);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function blankSearches(): iterable
    {
        yield 'absent' => [null];
        yield 'vide' => [''];
        yield 'espaces' => ["  \t "];
    }
}
