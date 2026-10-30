<?php

namespace Repas\Tests\Drive\InputAdapter;


use Repas\Drive\Application\Query\ListChoices\AcceptedProductDTO;
use Repas\Drive\Application\Query\ListChoices\ChoiceDTO;
use Repas\Drive\Application\Query\ListChoices\ChoiceProductDTO;
use Repas\Drive\Application\Query\ListChoices\ListChoicesQuery;
use Repas\Drive\Application\Query\ListChoices\PackSizeDTO;
use Repas\Drive\Application\Query\ListChoices\ProductContentDTO;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Application\Interface\DriveFeature;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListChoicesApiControllerTest extends QueryBusWebTestCase
{
    public function testAsksTheQueryForTheAuthenticatedUserAndReturnsTheDTOKeys(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListChoicesQuery::class, [
            new ChoiceDTO(
                'farine',
                'Farine',
                false,
                [new AcceptedProductDTO(1, new ChoiceProductDTO('p-1', '555111', '3017620422003', 'Farine T55 1 kg', new ProductContentDTO(1000, 'g'), new PackSizeDTO(20, 'tranche'), null))],
                [new ChoiceProductDTO('p-2', '999000', null, 'Farine complète bio 1 kg', null, null, null)],
                '2026-10-18T10:00:00+02:00',
                'farine de blé t55',
            ),
            new ChoiceDTO('sel', 'Sel', true, [], [], '2026-10-18T11:00:00+02:00', null),
        ]);

        // When
        $this->client->request('GET', '/api/v1/drive/choices?retailer=leclerc', server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(new ListChoicesQuery($user->getId(), Retailer::LECLERC), $this->queryBus->getLastAskedQuery());
        $this->assertSame([
            'member' => [
                [
                    'ingredientSlug' => 'farine',
                    'ingredientName' => 'Farine',
                    'haveAtHome' => false,
                    'accepted' => [['rank' => 1, 'product' => ['productId' => 'p-1', 'retailerProductId' => '555111', 'ean' => '3017620422003', 'label' => 'Farine T55 1 kg', 'content' => ['value' => 1000, 'unit' => 'g'], 'packSize' => ['value' => 20, 'unitSlug' => 'tranche']]]],
                    'rejected' => [['productId' => 'p-2', 'retailerProductId' => '999000', 'ean' => null, 'label' => 'Farine complète bio 1 kg', 'content' => null, 'packSize' => null]],
                    'updatedAt' => '2026-10-18T10:00:00+02:00',
                    'searchQuery' => 'farine de blé t55',
                ],
                ['ingredientSlug' => 'sel', 'ingredientName' => 'Sel', 'haveAtHome' => true, 'accepted' => [], 'rejected' => [], 'updatedAt' => '2026-10-18T11:00:00+02:00', 'searchQuery' => null],
            ],
            'totalItems' => 2,
        ], $this->jsonBody());
    }

    public function testAnEmptyListIsAnEmptyMember(): void
    {
        // Given
        $this->queryBus->willReturn(ListChoicesQuery::class, []);

        // When
        $this->client->request('GET', '/api/v1/drive/choices?retailer=leclerc', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testRefusesAMissingOrUnknownRetailer(): void
    {
        // Given
        $user = $this->savedUser();

        // When / Then
        foreach (['', '?retailer=inconnue', '?retailer='] as $query) {
            $this->client->request('GET', '/api/v1/drive/choices' . $query, server: $this->bearerFor($user));
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $query);
        }
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testAnswers404DriveDisabledWhenTheSwitchIsOff(): void
    {
        // Given
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));

        // When
        $this->client->request('GET', '/api/v1/drive/choices', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_DISABLED']], $this->jsonBody());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // When
        $this->client->request('GET', '/api/v1/drive/choices?retailer=leclerc');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
