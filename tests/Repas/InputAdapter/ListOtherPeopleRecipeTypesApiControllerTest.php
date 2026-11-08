<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListOtherPeopleRecipeTypes\ListOtherPeopleRecipeTypesQuery;
use Repas\Repas\Application\Query\ListOtherPeopleRecipeTypes\OtherPeopleRecipeTypeDTO;
use Repas\Repas\Application\Query\ListOtherPeopleRecipeTypes\OtherPeopleRecipeTypesDTO;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListOtherPeopleRecipeTypesApiControllerTest extends QueryBusWebTestCase
{
    public function testReturnsMembersAndTotalItems(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListOtherPeopleRecipeTypesQuery::class, new OtherPeopleRecipeTypesDTO([
            new OtherPeopleRecipeTypeDTO('plat', 'plat', 12),
            new OtherPeopleRecipeTypeDTO('dessert', 'dessert', 3),
        ], 2));

        // When
        $this->client->request('GET', '/api/v1/recipes/other-people/types', server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListOtherPeopleRecipeTypesQuery::class, $query);
        $this->assertSame([$user->getId(), false], [$query->userId, $query->isAdministrator]);
        $this->assertSame([
            'member' => [
                ['typeSlug' => 'plat', 'typeName' => 'plat', 'count' => 12],
                ['typeSlug' => 'dessert', 'typeName' => 'dessert', 'count' => 3],
            ],
            'totalItems' => 2,
        ], $this->jsonBody());
    }

    public function testTheQueryTellsThatTheUserIsAnAdministrator(): void
    {
        // Given
        $this->queryBus->willReturn(ListOtherPeopleRecipeTypesQuery::class, new OtherPeopleRecipeTypesDTO([], 0));

        // When
        $this->client->request('GET', '/api/v1/recipes/other-people/types', server: $this->bearerFor($this->savedUser(admin: true)));

        // Then
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListOtherPeopleRecipeTypesQuery::class, $query);
        $this->assertTrue($query->isAdministrator);
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/recipes/other-people/types');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
