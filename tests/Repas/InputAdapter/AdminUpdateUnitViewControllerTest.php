<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListUnits\ListUnitsQuery;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Tests\Helper\Builder\DTO\UnitDTOBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class AdminUpdateUnitViewControllerTest extends QueryBusWebTestCase
{
    public function testFormIsPrefilledWithTheUnit(): void
    {
        // Given
        static::getContainer()->get(UnitRepository::class)->save(
            new UnitBuilder()->withSlug('zzz-pincee')->withName('Zzz pincée')->withSymbol('zz')->build()
        );
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $crawler = $this->client->request('GET', '/admin/unit/zzz-pincee/update');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('Zzz pincée', $crawler->filter('input[name="update_unit[name]"]')->attr('value'));
        $this->assertSame('zz', $crawler->filter('input[name="update_unit[symbol]"]')->attr('value'));
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testValidSubmissionUpdatesTheUnitThenListsUnitsFromTheQuery(): void
    {
        // Given
        $unitRepository = static::getContainer()->get(UnitRepository::class);
        $unitRepository->save(new UnitBuilder()->withSlug('zzz-pincee')->withName('Zzz pincée')->withSymbol('zz')->build());
        $this->queryBus->willReturn(ListUnitsQuery::class, [
            new UnitDTOBuilder()->withSlug('zzz-pincee')->withName('zzz grosse pincée')->withSymbol('zzz')->build(),
        ]);
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $crawler = $this->client->request('POST', '/admin/unit/zzz-pincee/update', [
            'update_unit' => ['name' => 'Zzz grosse pincée', 'symbol' => 'zzz', '_token' => 'csrf-token'],
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertInstanceOf(ListUnitsQuery::class, $this->queryBus->getLastAskedQuery());
        $this->assertCount(1, $crawler->filter('#unit-zzz-pincee'));
        $updated = $unitRepository->findOneBySlug('zzz-pincee');
        $this->assertSame(['Zzz grosse pincée', 'zzz'], [$updated->getName(), $updated->getSymbol()]);
    }

    public function testNonAdminUserIsForbidden(): void
    {
        // Given
        $this->client->loginUser($this->savedUser());

        // When
        $this->client->request('GET', '/admin/unit/gramme/update');

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }
}
