<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListUnits\ListUnitsQuery;
use Repas\Tests\Helper\Builder\DTO\UnitDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class AdminGetAllUnitViewControllerTest extends QueryBusWebTestCase
{
    public function testPageRendersOneRowPerUnitFromTheDTOs(): void
    {
        // Given
        $this->queryBus->willReturn(ListUnitsQuery::class, [
            new UnitDTOBuilder()->withSlug('gramme')->withName('gramme')->withSymbol('g')->build(),
            new UnitDTOBuilder()->withSlug('cuillere-a-soupe')->withName('cuillère à soupe')->withSymbol('c. à s.')->build(),
        ]);
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $crawler = $this->client->request('GET', '/admin/unit');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertInstanceOf(ListUnitsQuery::class, $this->queryBus->getLastAskedQuery());
        $this->assertCount(2, $crawler->filter('tbody tr'));
        $this->assertSame(
            ['Cuillère à soupe', 'c. à s.'],
            array_map('trim', $crawler->filter('#unit-cuillere-a-soupe td')->slice(0, 2)->extract(['_text'])),
        );
        $this->assertSame('/admin/unit/cuillere-a-soupe/update', $crawler->filter('#unit-cuillere-a-soupe a[href*="update"]')->attr('href'));
    }

    public function testPageRendersNoRowWhenThereIsNoUnit(): void
    {
        // Given
        $this->queryBus->willReturn(ListUnitsQuery::class, []);
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $crawler = $this->client->request('GET', '/admin/unit');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertCount(0, $crawler->filter('tbody tr'));
    }

    public function testNonAdminUserIsForbidden(): void
    {
        // Given
        $this->client->loginUser($this->savedUser());

        // When
        $this->client->request('GET', '/admin/unit');

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
