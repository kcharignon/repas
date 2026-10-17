<?php

namespace Repas\Tests\Shared\Infrastructure\Feature;


use PHPUnit\Framework\TestCase;
use Repas\Shared\Infrastructure\Feature\EnvDriveFeature;

class EnvDriveFeatureTest extends TestCase
{
    public function testIsEnabledWhenTheSwitchIsOn(): void
    {
        // Given
        $feature = new EnvDriveFeature(true);

        // When
        $enabled = $feature->isEnabled();

        // Then
        $this->assertTrue($enabled);
    }

    public function testIsDisabledWhenTheSwitchIsOff(): void
    {
        // Given
        $feature = new EnvDriveFeature(false);

        // When
        $enabled = $feature->isEnabled();

        // Then
        $this->assertFalse($enabled);
    }
}
