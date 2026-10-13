<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListConversions\ListConversionsQuery;
use Repas\Tests\Helper\Builder\DTO\ConversionDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class AdminGetAllConversionViewControllerTest extends QueryBusWebTestCase
{
    public function testPageListsTheConversionsFromTheDTOs(): void
    {
        // Given
        $this->queryBus->willReturn(ListConversionsQuery::class, [
            new ConversionDTOBuilder()->withId('conversion-1')->withIngredient('beurre', 'beurre', 'createur@example.com')->withUnits('plaquette', 'plaquette', 'gramme', 'gramme')->withCoefficient(250)->build(),
            new ConversionDTOBuilder()->withId('conversion-2')->withIngredient(null, null)->withUnits('kilo', 'kilo', 'gramme', 'gramme')->withCoefficient(1000)->build(),
        ]);
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $crawler = $this->client->request('GET', '/admin/conversion');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertInstanceOf(ListConversionsQuery::class, $this->queryBus->getLastAskedQuery());
        $cells = static fn(string $id): array => array_map('trim', $crawler->filter("#conversion-$id td")->extract(['_text']));
        $this->assertSame(['Beurre', 'createur@example.com', 'Plaquette', '250', 'Gramme', ''], $cells('conversion-1'));
        $this->assertSame(['', '-', 'Kilo', '1000', 'Gramme', ''], $cells('conversion-2'));
        $this->assertSame('/admin/conversion/conversion-1/update', $crawler->filter('#conversion-conversion-1 a.btn-secondary')->attr('href'));
    }

    public function testRefusesANonAdministrator(): void
    {
        // Given
        $this->client->loginUser($this->savedUser());

        // When
        $this->client->request('GET', '/admin/conversion');

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
