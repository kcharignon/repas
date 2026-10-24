<?php

namespace Repas\Tests\Shared\Infrastructure\Feature;


use PHPUnit\Framework\TestCase;
use Repas\Shared\Infrastructure\Feature\EnvIngredientSeedingFeature;

class EnvIngredientSeedingFeatureTest extends TestCase
{
    public function testIsEnabledWhenTheSwitchIsOn(): void
    {
        $this->assertTrue(new EnvIngredientSeedingFeature(true)->isEnabled());
    }

    public function testIsDisabledWhenTheSwitchIsOff(): void
    {
        $this->assertFalse(new EnvIngredientSeedingFeature(false)->isEnabled());
    }
}
