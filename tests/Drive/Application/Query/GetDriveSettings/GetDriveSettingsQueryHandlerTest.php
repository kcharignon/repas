<?php

namespace Repas\Tests\Drive\Application\Query\GetDriveSettings;


use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Query\GetDriveSettings\GetDriveSettingsQuery;
use Repas\Drive\Application\Query\GetDriveSettings\GetDriveSettingsQueryHandler;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Tests\Helper\Builder\DTO\DriveSettingsDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\DriveSettingsInMemoryLoader;

class GetDriveSettingsQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturnsForTheRequestedUserWithTheRetailerOfTheSelection(): void
    {
        // Given
        $settings = new DriveSettingsDTOBuilder()->build();
        $loader = new DriveSettingsInMemoryLoader($settings);

        // When
        $result = new GetDriveSettingsQueryHandler($loader)(new GetDriveSettingsQuery('user-42'));

        // Then : sans enseigne demandée, le loader choisit celle de la sélection (DRV-703)
        $this->assertSame($settings, $result);
        $this->assertSame([['user-42', null]], $loader->getCalls);
    }

    public function testTransmitsTheRequestedRetailerToTheLoader(): void
    {
        // Given
        $loader = new DriveSettingsInMemoryLoader();

        // When
        new GetDriveSettingsQueryHandler($loader)(new GetDriveSettingsQuery('user-42', Retailer::LECLERC));

        // Then
        $this->assertSame([['user-42', Retailer::LECLERC]], $loader->getCalls);
    }
}
