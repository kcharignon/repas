<?php

namespace Repas\Tests\Shared\Infrastructure\Feature;


use Repas\Shared\Application\Interface\DriveFeature;
use Repas\Shared\Infrastructure\Feature\EnvDriveFeature;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le port est câblé sur la variable d'environnement, et l'environnement de test allume la fonction (`.env.test`,
 * `phpunit.dist.xml`) : les futurs tests des controllers du drive partent d'un interrupteur allumé.
 */
class DriveFeatureWiringTest extends KernelTestCase
{
    public function testThePortIsWiredOnTheEnvironmentVariableAndTheTestEnvironmentTurnsItOn(): void
    {
        // Given
        self::bootKernel();

        // When
        $feature = self::getContainer()->get(DriveFeature::class);

        // Then
        $this->assertInstanceOf(EnvDriveFeature::class, $feature);
        $this->assertTrue($feature->isEnabled());
    }
}
