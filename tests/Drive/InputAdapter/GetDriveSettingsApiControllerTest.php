<?php

namespace Repas\Tests\Drive\InputAdapter;


use Repas\Drive\Application\Query\GetDriveSettings\DriveRetailerDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveSettingsDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveStoreDTO;
use Repas\Drive\Application\Query\GetDriveSettings\GetDriveSettingsQuery;
use Repas\Drive\Application\Query\GetDriveSettings\RetailerSelectionDTO;
use Repas\Shared\Application\Interface\DriveFeature;
use Repas\Tests\Helper\Builder\DTO\DriveSettingsDTOBuilder;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetDriveSettingsApiControllerTest extends QueryBusWebTestCase
{
    public function testAsksTheQueryForTheAuthenticatedUserAndReturnsTheDTOKeys(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(GetDriveSettingsQuery::class, new DriveSettingsDTOBuilder()->build());

        // When
        $this->client->request('GET', '/api/v1/drive/settings', server: $this->bearerFor($user));

        // Then : les anciens champs, puis les champs de la sélection (DRV-703), tous toujours écrits
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(new GetDriveSettingsQuery($user->getId()), $this->queryBus->getLastAskedQuery());
        $store = ['id' => '123456', 'label' => 'Ville-Test', 'baseUrl' => 'https://exemple.leclercdrive.fr/magasin-123456-123456'];
        $this->assertSame(
            [
                'enabled' => true,
                'retailer' => 'leclerc',
                'store' => $store,
                'retailers' => [['retailer' => 'leclerc', 'name' => 'Leclerc', 'configured' => true, 'isDefault' => false, 'store' => $store]],
                'defaultRetailer' => null,
                'lastUsedRetailer' => null,
                'selection' => ['mode' => 'AUTO', 'retailer' => 'leclerc', 'candidates' => ['leclerc']],
            ],
            $this->jsonBody(),
        );
    }

    public function testWithoutStoreTheStoreIsNullAndTheSelectionIsNone(): void
    {
        // Given
        $this->queryBus->willReturn(GetDriveSettingsQuery::class, new DriveSettingsDTOBuilder()->unconfigured()->build());

        // When
        $this->client->request('GET', '/api/v1/drive/settings', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(
            [
                'enabled' => true,
                'retailer' => 'leclerc',
                'store' => null,
                'retailers' => [['retailer' => 'leclerc', 'name' => 'Leclerc', 'configured' => false, 'isDefault' => false, 'store' => null]],
                'defaultRetailer' => null,
                'lastUsedRetailer' => null,
                'selection' => ['mode' => 'NONE', 'retailer' => null, 'candidates' => []],
            ],
            $this->jsonBody(),
        );
    }

    public function testTheDefaultAndTheLastRetailerAreWrittenWhenTheyExist(): void
    {
        // Given
        $store = new DriveStoreDTO('123456', null, 'https://exemple.leclercdrive.fr/magasin-123456-123456');
        $this->queryBus->willReturn(GetDriveSettingsQuery::class, new DriveSettingsDTO(
            true,
            'leclerc',
            $store,
            [new DriveRetailerDTO('leclerc', 'Leclerc', true, true, $store)],
            'leclerc',
            'leclerc',
            new RetailerSelectionDTO('ASK', 'leclerc', ['leclerc', 'auchan']),
        ));

        // When
        $this->client->request('GET', '/api/v1/drive/settings', server: $this->bearerFor($this->savedUser()));

        // Then
        $body = $this->jsonBody();
        $this->assertSame(['leclerc', 'leclerc', true], [$body['defaultRetailer'], $body['lastUsedRetailer'], $body['retailers'][0]['isDefault']]);
        $this->assertSame(['mode' => 'ASK', 'retailer' => 'leclerc', 'candidates' => ['leclerc', 'auchan']], $body['selection']);
    }

    public function testAnswers200WithEnabledFalseWhenTheSwitchIsOff(): void
    {
        // Given
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));
        $this->queryBus->willReturn(GetDriveSettingsQuery::class, new DriveSettingsDTOBuilder()->disabled()->unconfigured()->build());

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
