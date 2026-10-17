<?php

namespace Repas\Tests\Shared\Infrastructure\Http;


use Repas\Shared\Application\Interface\DriveFeature;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\Http\DriveProbeController;
use Repas\Tests\Helper\QueryBusWebTestCase;

/**
 * Câblage réel de l'interrupteur du drive sur des routes d'essai (`DriveProbeController`, environnement de test) :
 * sécurité, listener `#[RequiresDrive]` et mise en forme de l'erreur par `ApiExceptionListener`.
 */
class DriveSwitchApiTest extends QueryBusWebTestCase
{
    public function testAMarkedRouteAnswersWhenTheSwitchIsOn(): void
    {
        // Given
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(true));

        // When
        $this->client->request('GET', DriveProbeController::REQUIRED_PATH, server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['probe' => 'drive-required'], $this->jsonBody());
    }

    public function testAMarkedRouteAnswers404DriveDisabledWhenTheSwitchIsOff(): void
    {
        // Given
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));

        // When
        $this->client->request('GET', DriveProbeController::REQUIRED_PATH, server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_DISABLED']], $this->jsonBody());
    }

    public function testAnUnmarkedRouteIgnoresTheSwitch(): void
    {
        // Given
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));

        // When
        $this->client->request('GET', DriveProbeController::FREE_PATH, server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['probe' => 'drive-free'], $this->jsonBody());
    }

    public function testAnAnonymousCallStillGets401WhenTheSwitchIsOff(): void
    {
        // Given
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));

        // When
        $this->client->request('GET', DriveProbeController::REQUIRED_PATH);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }
}
