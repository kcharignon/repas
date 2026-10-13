<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListOtherPeopleRecipes\ListOtherPeopleRecipesQuery;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipeDTO;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetAllOtherPeopleRecipesViewControllerTest extends QueryBusWebTestCase
{
    public function testPageListsTheRecipesOfOtherPeopleFromTheDTOs(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListOtherPeopleRecipesQuery::class, [
            new OtherPeopleRecipeDTO('recipe-1', 'pâtes carbonara', 'plat'),
            new OtherPeopleRecipeDTO('recipe-2', 'tarte au citron', 'dessert'),
        ]);
        $this->client->loginUser($user);

        // When
        $crawler = $this->client->request('GET', '/recipes/other-people');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListOtherPeopleRecipesQuery::class, $query);
        $this->assertSame($user->getId(), $query->userId);
        $cells = static fn(string $id): array => array_map('trim', $crawler->filter("#recipe-$id td")->extract(['_text']));
        $this->assertSame(['Pâtes carbonara', 'Plat', ''], $cells('recipe-1'));
        $this->assertSame(['Tarte au citron', 'Dessert', ''], $cells('recipe-2'));
        $this->assertSame('/recipe/recipe-1/copy', $crawler->filter('#recipe-recipe-1 button')->attr('data-url'));
    }

    public function testPageIsStillDisplayedWithoutRecipe(): void
    {
        // Given
        $this->queryBus->willReturn(ListOtherPeopleRecipesQuery::class, []);
        $this->client->loginUser($this->savedUser());

        // When
        $crawler = $this->client->request('GET', '/recipes/other-people');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertCount(0, $crawler->filter('tbody tr'));
    }

    public function testAnonymousUserIsRedirectedToTheLoginForm(): void
    {
        // When
        $this->client->request('GET', '/recipes/other-people');

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
