<?php

namespace Repas\Tests\Drive\Application\Query\GetDriveSettings;


use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Query\GetDriveSettings\DriveSettingsDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveStoreDTO;
use Repas\Drive\Application\Query\GetDriveSettings\GetDriveSettingsQuery;
use Repas\Drive\Application\Query\GetDriveSettings\GetDriveSettingsQueryHandler;
use Repas\Tests\Helper\InMemoryLoader\DriveSettingsInMemoryLoader;

class GetDriveSettingsQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturnsForTheRequestedUser(): void
    {
        // Given
        $settings = new DriveSettingsDTO(true, 'leclerc', new DriveStoreDTO('123456', 'Ville-Test', 'https://exemple.leclercdrive.fr/magasin-123456-123456'));
        $loader = new DriveSettingsInMemoryLoader($settings);

        // When
        $result = new GetDriveSettingsQueryHandler($loader)(new GetDriveSettingsQuery('user-42'));

        // Then
        $this->assertSame($settings, $result);
        $this->assertSame(['user-42'], $loader->getCalls);
    }
}
