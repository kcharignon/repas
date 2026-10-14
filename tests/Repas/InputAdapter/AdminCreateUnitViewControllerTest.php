<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListUnits\ListUnitsQuery;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Tests\Helper\Builder\DTO\UnitDTOBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class AdminCreateUnitViewControllerTest extends QueryBusWebTestCase
{
    public function testFormIsDisplayed(): void
    {
        // Given
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $crawler = $this->client->request('GET', '/admin/unit/create');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $crawler->filter('form input[name="create_unit[name]"]'));
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testValidSubmissionCreatesTheUnitThenListsUnitsFromTheQuery(): void
    {
        // Given
        $this->queryBus->willReturn(ListUnitsQuery::class, [
            new UnitDTOBuilder()->withSlug('zzz-pincee')->withName('zzz pincée')->withSymbol('zz')->build(),
        ]);
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $crawler = $this->client->request('POST', '/admin/unit/create', [
            'create_unit' => ['name' => 'Zzz pincée', 'symbol' => 'zz', '_token' => 'csrf-token'],
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertInstanceOf(ListUnitsQuery::class, $this->queryBus->getLastAskedQuery());
        $this->assertCount(1, $crawler->filter('#unit-zzz-pincee'));
        $created = static::getContainer()->get(UnitRepository::class)->findOneBySlug('zzz-pincee');
        $this->assertSame(['Zzz pincée', 'zz'], [$created->getName(), $created->getSymbol()]);
    }

    public function testRefusesANonAdministrator(): void
    {
        // Given
        $this->client->loginUser($this->savedUser());

        // When
        $this->client->request('POST', '/admin/unit/create', [
            'create_unit' => ['name' => 'Zzz pincée', 'symbol' => 'zz', '_token' => 'csrf-token'],
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->expectExceptionObject(UnitException::notFound('zzz-pincee'));
        static::getContainer()->get(UnitRepository::class)->findOneBySlug('zzz-pincee');
    }

    public function testRedirectsAnAnonymousVisitorToTheLogin(): void
    {
        // When
        $this->client->request('GET', '/admin/unit/create');

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirection());
        $this->assertStringContainsString('/login', $this->client->getResponse()->headers->get('Location'));
    }

    public function testADuplicateNameShowsAnErrorAndKeepsTheFirstUnit(): void
    {
        // Given
        $this->persister->saveUnit(new UnitBuilder()->withName('Zzz pincée')->withSymbol('zz')->build());
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $crawler = $this->client->request('POST', '/admin/unit/create', [
            'create_unit' => ['name' => 'zzz pincee', 'symbol' => 'autre', '_token' => 'csrf-token'],
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Une unité de ce nom existe déjà.', $crawler->text());
        $this->assertSame('zz', static::getContainer()->get(UnitRepository::class)->findOneBySlug('zzz-pincee')->getSymbol());
    }
}
