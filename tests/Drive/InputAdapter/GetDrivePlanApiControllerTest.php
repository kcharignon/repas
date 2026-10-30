<?php

namespace Repas\Tests\Drive\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Drive\Application\Query\GetDrivePlan\GetDrivePlanQuery;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Application\Interface\DriveFeature;
use Repas\Tests\Helper\Builder\DTO\DrivePlanDTOBuilder;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetDrivePlanApiControllerTest extends QueryBusWebTestCase
{
    public function testAsksTheQueryForTheAuthenticatedUserAndReturnsTheDTOKeys(): void
    {
        // Given
        $user = $this->savedUser();
        $list = $this->savedShoppingList($user);
        $this->queryBus->willReturn(GetDrivePlanQuery::class, new DrivePlanDTOBuilder()->build());

        // When
        $this->client->request('GET', '/api/v1/drive/plans/' . $list->getId(), server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(new GetDrivePlanQuery($user->getId(), $list->getId()), $this->queryBus->getLastAskedQuery());
        $this->assertSame([
            'shoppingListId' => 'list-1',
            'listVersion' => 5,
            'retailer' => 'leclerc',
            'store' => ['id' => '123456', 'label' => null, 'baseUrl' => 'https://exemple.leclercdrive.fr/magasin-123456-123456'],
            'generatedAt' => '2026-10-18T10:00:00+02:00',
            'counts' => ['known' => 1, 'new' => 1, 'haveAtHome' => 1],
            'lines' => [
                [
                    'ingredientSlug' => 'farine',
                    'ingredientName' => 'Farine',
                    'needed' => ['quantity' => 300, 'unitSymbol' => 'g', 'dimension' => 'WEIGHT', 'baseFactor' => 1, 'unitSlug' => 'gramme', 'unitName' => 'gramme'],
                    'status' => 'KNOWN',
                    'products' => [
                        ['rank' => 1, 'retailerProductId' => '123456', 'ean' => null, 'label' => 'Farine de blé T55 1 kg', 'content' => ['value' => 1000, 'unit' => 'g'], 'packs' => 1, 'packsStatus' => 'COMPUTED', 'packSize' => null],
                        ['rank' => 2, 'retailerProductId' => '222333', 'ean' => '3017620422003', 'label' => 'Farine T45', 'content' => null, 'packs' => 1, 'packsStatus' => 'COMPUTED', 'packSize' => ['value' => 500, 'unitSlug' => 'gramme']],
                    ],
                    'rejected' => [['retailerProductId' => '999000', 'label' => 'Farine complète bio 1 kg']],
                    'searchQuery' => 'Farine',
                    'rayonHints' => [4001, 4002],
                ],
                ['ingredientSlug' => 'poivre', 'ingredientName' => 'Poivre', 'needed' => ['quantity' => 1, 'unitSymbol' => 'paquet', 'dimension' => 'PACKAGE', 'baseFactor' => 1, 'unitSlug' => 'paquet', 'unitName' => 'paquet'], 'status' => 'NEW', 'products' => [], 'rejected' => [], 'searchQuery' => 'Poivre', 'rayonHints' => []],
                ['ingredientSlug' => 'sel', 'ingredientName' => 'Sel', 'needed' => ['quantity' => 2, 'unitSymbol' => 'cuillère à soupe', 'dimension' => null, 'baseFactor' => null, 'unitSlug' => 'cuillere-a-soupe', 'unitName' => 'cuillère à soupe'], 'status' => 'HAVE_AT_HOME', 'products' => [], 'rejected' => [], 'searchQuery' => 'Sel', 'rayonHints' => []],
            ],
        ], $this->jsonBody());
    }

    public function testTheRetailerOfTheQueryStringIsGivenToTheQuery(): void
    {
        // Given
        $user = $this->savedUser();
        $list = $this->savedShoppingList($user);
        $this->queryBus->willReturn(GetDrivePlanQuery::class, new DrivePlanDTOBuilder()->build());

        // When
        $this->client->request('GET', '/api/v1/drive/plans/' . $list->getId() . '?retailer=leclerc', server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(new GetDrivePlanQuery($user->getId(), $list->getId(), Retailer::LECLERC), $this->queryBus->getLastAskedQuery());
    }

    public function testAnUnknownRetailerIsRefusedWith422(): void
    {
        // Given
        $user = $this->savedUser();
        $list = $this->savedShoppingList($user);

        // When
        $this->client->request('GET', '/api/v1/drive/plans/' . $list->getId() . '?retailer=inconnue', server: $this->bearerFor($user));

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testRefusesAListOfAnotherUser(): void
    {
        // Given
        $list = $this->savedShoppingList($this->savedUser());

        // When
        $this->client->request('GET', '/api/v1/drive/plans/' . $list->getId(), server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testAnUnknownListIsNotFound(): void
    {
        // When
        $this->client->request('GET', '/api/v1/drive/plans/liste-inconnue', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    #[DataProvider('conflicts')]
    public function testRelaysTheConflictsOfTheQuery(DriveException $exception): void
    {
        // Given
        $user = $this->savedUser();
        $list = $this->savedShoppingList($user);
        $this->queryBus->willThrow(GetDrivePlanQuery::class, $exception);

        // When
        $this->client->request('GET', '/api/v1/drive/plans/' . $list->getId(), server: $this->bearerFor($user));

        // Then
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => $exception->getMessage()]], $this->jsonBody());
    }

    public static function conflicts(): iterable
    {
        yield 'pas de magasin' => [DriveException::notConfigured()];
        yield 'liste non envoyée' => [DriveException::shoppingListNotSent()];
    }

    public function testAnswers404DriveDisabledWhenTheSwitchIsOff(): void
    {
        // Given
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));
        $user = $this->savedUser();
        $list = $this->savedShoppingList($user);

        // When
        $this->client->request('GET', '/api/v1/drive/plans/' . $list->getId(), server: $this->bearerFor($user));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_DISABLED']], $this->jsonBody());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // When
        $this->client->request('GET', '/api/v1/drive/plans/list-1');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
