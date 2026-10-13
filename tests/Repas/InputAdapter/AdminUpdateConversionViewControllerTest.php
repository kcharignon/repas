<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetConversion\GetConversionQuery;
use Repas\Repas\Application\Query\ListConversions\ConversionDTO;
use Repas\Repas\Application\Query\ListAvailableIngredients\IngredientOptionDTO;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DTO\ConversionDTOBuilder;
use Repas\Tests\Helper\Builder\DTO\UnitDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class AdminUpdateConversionViewControllerTest extends QueryBusWebTestCase
{
    public function testFormIsPrefilledFromTheConversionDTO(): void
    {
        // Given
        $conversion = $this->savedConversion();
        $this->queryBus->willReturn(GetConversionQuery::class, $this->dtoOf($conversion->getId()));
        $this->offerOptions();
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $crawler = $this->client->request('GET', '/admin/conversion/' . $conversion->getId() . '/update');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getAskedQuery(GetConversionQuery::class);
        $this->assertInstanceOf(GetConversionQuery::class, $query);
        $this->assertSame($conversion->getId(), $query->conversionId);
        $this->assertSame('plaquette', $crawler->filter('select[name="update_conversion[startUnit]"] option[selected]')->attr('value'));
        $this->assertSame('gramme', $crawler->filter('select[name="update_conversion[endUnit]"] option[selected]')->attr('value'));
        $this->assertSame('250', $crawler->filter('input[name="update_conversion[coefficient]"]')->attr('value'));
        $this->assertSame('beurre', $crawler->filter('select[name="update_conversion[ingredient]"] option[selected]')->attr('value'));
    }

    public function testValidSubmissionUpdatesTheConversionThenRedirectsToTheList(): void
    {
        // Given
        $conversion = $this->savedConversion();
        $this->queryBus->willReturn(GetConversionQuery::class, $this->dtoOf($conversion->getId()));
        $this->offerOptions();
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $this->client->request('POST', '/admin/conversion/' . $conversion->getId() . '/update', [
            'update_conversion' => [
                'startUnit' => 'plaquette',
                'endUnit' => 'gramme',
                'coefficient' => '225',
                'ingredient' => 'beurre',
                '_token' => 'csrf-token',
            ],
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect('/admin/conversion'));
        $this->assertSame(225.0, static::getContainer()->get(ConversionRepository::class)->findById($conversion->getId())->getCoefficient());
    }

    public function testRefusesANonAdministrator(): void
    {
        // Given
        $this->client->loginUser($this->savedUser());

        // When
        $this->client->request('GET', '/admin/conversion/any-id/update');

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    private function offerOptions(): void
    {
        $this->willOfferFormOptions(
            units: [new UnitDTOBuilder()->withSlug('plaquette')->withName('plaquette')->build(), new UnitDTOBuilder()->withSlug('gramme')->withName('gramme')->build()],
            ingredients: [new IngredientOptionDTO('beurre', 'beurre')],
        );
    }

    private function savedConversion(): Conversion
    {
        $conversion = new ConversionBuilder()->isBlockToGrammeForButter()->build();
        $this->persister->saveConversion($conversion);

        return $conversion;
    }

    private function dtoOf(string $id): ConversionDTO
    {
        return new ConversionDTOBuilder()->withId($id)->withIngredient('beurre', 'beurre')->withUnits('plaquette', 'plaquette', 'gramme', 'gramme')->withCoefficient(250)->build();
    }
}
