<?php

namespace Repas\Tests\Repas\Infrastructure\Feature;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Infrastructure\Feature\EnvIngredientSeedingFeature;

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
