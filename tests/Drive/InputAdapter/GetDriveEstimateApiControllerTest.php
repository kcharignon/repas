<?php

namespace Repas\Tests\Drive\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Drive\Application\Query\GetDriveEstimate\GetDriveEstimateQuery;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Application\Interface\DriveFeature;
use Repas\Tests\Helper\Builder\DTO\DriveEstimateDTOBuilder;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetDriveEstimateApiControllerTest extends QueryBusWebTestCase
{
    public function testAsksTheQueryForTheAuthenticatedUserAndReturnsTheDTOKeys(): void
    {
        // Given
        $user = $this->savedUser();
        $list = $this->savedShoppingList($user);
        $this->queryBus->willReturn(GetDriveEstimateQuery::class, new DriveEstimateDTOBuilder()->build());

        // When
        $this->client->request('GET', '/api/v1/drive/estimates/' . $list->getId(), server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(new GetDriveEstimateQuery($user->getId(), $list->getId()), $this->queryBus->getLastAskedQuery());
        $this->assertSame([
            'shoppingListId' => 'list-1',
            'retailer' => 'leclerc',
            'store' => ['id' => '123456', 'label' => null, 'baseUrl' => 'https://exemple.leclercdrive.fr/magasin-123456-123456'],
            'estimatedTotalCents' => 258,
            'estimatedLines' => 1,
            'unestimatedLines' => 3,
            'coverage' => 0.25,
            'oldestPriceAt' => '2026-10-12T09:30:00+02:00',
            'newestPriceAt' => '2026-10-12T09:30:00+02:00',
            'lines' => [
                ['ingredientSlug' => 'farine', 'ingredientName' => 'Farine', 'productLabel' => 'Farine de blé T55 1 kg', 'packs' => 2, 'unitPriceCents' => 129, 'subtotalCents' => 258, 'priceSeenAt' => '2026-10-12T09:30:00+02:00', 'status' => 'ESTIMATED'],
                ['ingredientSlug' => 'lait', 'ingredientName' => 'Lait', 'productLabel' => 'Lait demi-écrémé 1 l', 'packs' => null, 'unitPriceCents' => 95, 'subtotalCents' => null, 'priceSeenAt' => '2026-10-12T09:30:00+02:00', 'status' => 'TO_CHECK'],
                ['ingredientSlug' => 'poivre', 'ingredientName' => 'Poivre', 'productLabel' => null, 'packs' => null, 'unitPriceCents' => null, 'subtotalCents' => null, 'priceSeenAt' => null, 'status' => 'NO_PRODUCT'],
                ['ingredientSlug' => 'riz', 'ingredientName' => 'Riz', 'productLabel' => 'Riz long 1 kg', 'packs' => null, 'unitPriceCents' => null, 'subtotalCents' => null, 'priceSeenAt' => null, 'status' => 'NO_PRICE'],
                ['ingredientSlug' => 'sel', 'ingredientName' => 'Sel', 'productLabel' => null, 'packs' => null, 'unitPriceCents' => null, 'subtotalCents' => null, 'priceSeenAt' => null, 'status' => 'HAVE_AT_HOME'],
            ],
            'disclaimer' => "Estimation d'après les derniers prix vus ; le prix réel est celui du drive.",
        ], $this->jsonBody());
    }

    public function testTheRetailerOfTheQueryStringIsGivenToTheQuery(): void
    {
        // Given
        $user = $this->savedUser();
        $list = $this->savedShoppingList($user);
        $this->queryBus->willReturn(GetDriveEstimateQuery::class, new DriveEstimateDTOBuilder()->build());

        // When
        $this->client->request('GET', '/api/v1/drive/estimates/' . $list->getId() . '?retailer=leclerc', server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(new GetDriveEstimateQuery($user->getId(), $list->getId(), Retailer::LECLERC), $this->queryBus->getLastAskedQuery());
    }

    public function testAnUnknownRetailerIsRefusedWith422(): void
    {
        // Given
        $user = $this->savedUser();
        $list = $this->savedShoppingList($user);

        // When
        $this->client->request('GET', '/api/v1/drive/estimates/' . $list->getId() . '?retailer=inconnue', server: $this->bearerFor($user));

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testRefusesAListOfAnotherUser(): void
    {
        // Given
        $list = $this->savedShoppingList($this->savedUser());

        // When
        $this->client->request('GET', '/api/v1/drive/estimates/' . $list->getId(), server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testAnUnknownListIsNotFound(): void
    {
        // When
        $this->client->request('GET', '/api/v1/drive/estimates/liste-inconnue', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    #[DataProvider('conflicts')]
    public function testRelaysTheConflictOfTheQuery(DriveException $exception): void
    {
        // Given
        $user = $this->savedUser();
        $list = $this->savedShoppingList($user);
        $this->queryBus->willThrow(GetDriveEstimateQuery::class, $exception);

        // When
        $this->client->request('GET', '/api/v1/drive/estimates/' . $list->getId(), server: $this->bearerFor($user));

        // Then
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => $exception->getMessage()]], $this->jsonBody());
    }

    public static function conflicts(): iterable
    {
        yield 'pas de magasin' => [DriveException::notConfigured()];
    }

    public function testAnswers404DriveDisabledWhenTheSwitchIsOff(): void
    {
        // Given
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));
        $user = $this->savedUser();
        $list = $this->savedShoppingList($user);

        // When
        $this->client->request('GET', '/api/v1/drive/estimates/' . $list->getId(), server: $this->bearerFor($user));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_DISABLED']], $this->jsonBody());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // When
        $this->client->request('GET', '/api/v1/drive/estimates/list-1');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
