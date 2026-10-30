<?php

namespace Repas\Tests\Shared\Infrastructure\Feature;


use Repas\Drive\Domain\Model\Retailer;
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

    public function testTheRetailersAreWiredOnTheVariableAndTheRegistryAndTheTestEnvironmentLightsTheOnesOfToday(): void
    {
        // Given
        self::bootKernel();

        // When
        $feature = self::getContainer()->get(DriveFeature::class);

        // Then : `.env.test` et `phpunit.dist.xml` allument l'enseigne de la production d'aujourd'hui, et le registre du drive est la liste des identifiants connus
        $this->assertSame(Retailer::IDS, $feature->enabledRetailers());
        $this->assertSame(Retailer::IDS, self::getContainer()->getParameter('repas.drive.known_retailers'));
        $this->assertTrue($feature->isRetailerEnabled(Retailer::default()->value));
    }
}
