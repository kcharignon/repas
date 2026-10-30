<?php

namespace Repas\Tests\Drive\InputAdapter;


use Repas\Drive\Application\Query\GetDriveSettings\DriveRetailerDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveSettingsDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveStoreDTO;
use Repas\Drive\Application\Query\GetDriveSettings\GetDriveSettingsQuery;
use Repas\Shared\Application\Interface\DriveFeature;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetDriveSettingsApiControllerTest extends QueryBusWebTestCase
{
    public function testAsksTheQueryForTheAuthenticatedUserAndReturnsTheDTOKeys(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(GetDriveSettingsQuery::class, new DriveSettingsDTO(true, 'leclerc', $store = new DriveStoreDTO('123456', 'Ville-Test', 'https://exemple.leclercdrive.fr/magasin-123456-123456'), [new DriveRetailerDTO('leclerc', 'Leclerc', true, $store)]));

        // When
        $this->client->request('GET', '/api/v1/drive/settings', server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(new GetDriveSettingsQuery($user->getId()), $this->queryBus->getLastAskedQuery());
        $this->assertSame(
            ['enabled' => true, 'retailer' => 'leclerc', 'store' => ['id' => '123456', 'label' => 'Ville-Test', 'baseUrl' => 'https://exemple.leclercdrive.fr/magasin-123456-123456'], 'retailers' => [['retailer' => 'leclerc', 'name' => 'Leclerc', 'configured' => true, 'store' => ['id' => '123456', 'label' => 'Ville-Test', 'baseUrl' => 'https://exemple.leclercdrive.fr/magasin-123456-123456']]]],
            $this->jsonBody(),
        );
    }

    public function testWithoutStoreTheStoreIsNull(): void
    {
        // Given
        $this->queryBus->willReturn(GetDriveSettingsQuery::class, new DriveSettingsDTO(true, 'leclerc', null, [new DriveRetailerDTO('leclerc', 'Leclerc', false, null)]));

        // When
        $this->client->request('GET', '/api/v1/drive/settings', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['enabled' => true, 'retailer' => 'leclerc', 'store' => null, 'retailers' => [['retailer' => 'leclerc', 'name' => 'Leclerc', 'configured' => false, 'store' => null]]], $this->jsonBody());
    }

    public function testAnswers200WithEnabledFalseWhenTheSwitchIsOff(): void
    {
        // Given
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));
        $this->queryBus->willReturn(GetDriveSettingsQuery::class, new DriveSettingsDTO(false, 'leclerc', null, [new DriveRetailerDTO('leclerc', 'Leclerc', false, null)]));

        // When
        $this->client->request('GET', '/api/v1/drive/settings', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($this->jsonBody()['enabled']);
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // When
        $this->client->request('GET', '/api/v1/drive/settings');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
