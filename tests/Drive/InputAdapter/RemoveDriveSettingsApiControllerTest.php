<?php

namespace Repas\Tests\Drive\InputAdapter;


use Repas\Drive\Application\Command\RemoveDriveSettings\RemoveDriveSettingsCommand;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Application\Interface\DriveFeature;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\QueryBusWebTestCase;

class RemoveDriveSettingsApiControllerTest extends QueryBusWebTestCase
{
    public function testTheConnectedUserRemovesTheDriveOfARetailer(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/drive/settings?retailer=leclerc', server: $this->bearerFor($user));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new RemoveDriveSettingsCommand($user->getId(), Retailer::LECLERC)], $commandBus->dispatched());
    }

    public function testRefusesAMissingOrUnknownRetailer(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When / Then : sans enseigne la route ne retire rien « par défaut »
        foreach (['', '?retailer=', '?retailer=inconnue', '?store=123456'] as $query) {
            $this->client->request('DELETE', '/api/v1/drive/settings' . $query, server: $this->bearerFor($user));
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $query);
        }
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnswers404DriveDisabledWhenTheSwitchIsOff(): void
    {
        // Given
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/drive/settings', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_DISABLED']], $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/drive/settings?retailer=leclerc');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }
}
