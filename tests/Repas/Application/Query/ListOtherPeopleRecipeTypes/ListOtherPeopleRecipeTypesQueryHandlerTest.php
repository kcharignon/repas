<?php

namespace Repas\Tests\Repas\Application\Query\ListOtherPeopleRecipeTypes;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListOtherPeopleRecipeTypes\ListOtherPeopleRecipeTypesQuery;
use Repas\Repas\Application\Query\ListOtherPeopleRecipeTypes\ListOtherPeopleRecipeTypesQueryHandler;
use Repas\Repas\Application\Query\ListOtherPeopleRecipeTypes\OtherPeopleRecipeTypeDTO;
use Repas\Repas\Application\Query\ListOtherPeopleRecipeTypes\OtherPeopleRecipeTypesDTO;
use Repas\Tests\Helper\InMemoryLoader\RecipeInMemoryLoader;

class ListOtherPeopleRecipeTypesQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $types = new OtherPeopleRecipeTypesDTO([new OtherPeopleRecipeTypeDTO('plat', 'plat', 4)], 1);
        $handler = new ListOtherPeopleRecipeTypesQueryHandler(new RecipeInMemoryLoader(otherPeopleTypes: $types));

        // When
        $result = $handler(new ListOtherPeopleRecipeTypesQuery('user-1'));

        // Then
        $this->assertSame($types, $result);
    }

    public function testForwardsTheUserAndWhetherHeIsAnAdministratorToTheLoader(): void
    {
        // Given
        $loader = new RecipeInMemoryLoader();
        $handler = new ListOtherPeopleRecipeTypesQueryHandler($loader);

        // When
        $handler(new ListOtherPeopleRecipeTypesQuery('user-1'));
        $handler(new ListOtherPeopleRecipeTypesQuery('admin-1', true));

        // Then
        $this->assertSame([['user-1', false], ['admin-1', true]], $loader->listOtherPeopleTypesCalls);
    }
}
