<?php

namespace Repas\Tests\Repas\Application\Query\ListOtherPeopleRecipes;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\ListOtherPeopleRecipesQuery;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\ListOtherPeopleRecipesQueryHandler;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipeDTO;
use Repas\Tests\Helper\InMemoryLoader\RecipeInMemoryLoader;

class ListOtherPeopleRecipesQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $recipes = [new OtherPeopleRecipeDTO('recipe-1', 'omelette', 'plat')];
        $handler = new ListOtherPeopleRecipesQueryHandler(new RecipeInMemoryLoader(otherPeopleRecipes: $recipes));

        // When
        $result = $handler(new ListOtherPeopleRecipesQuery('user-1'));

        // Then
        $this->assertSame($recipes, $result);
    }

    public function testForwardsTheUserToTheLoader(): void
    {
        // Given
        $loader = new RecipeInMemoryLoader();
        $handler = new ListOtherPeopleRecipesQueryHandler($loader);

        // When
        $handler(new ListOtherPeopleRecipesQuery('user-1'));

        // Then
        $this->assertSame(['user-1'], $loader->listOfOtherPeopleCalls);
    }
}
