<?php

namespace Repas\Tests\Voice\Infrastructure\Feature;


use PHPUnit\Framework\TestCase;
use Repas\Voice\Infrastructure\Feature\EnvVoiceFeature;

class EnvVoiceFeatureTest extends TestCase
{
    public function testIsEnabledWhenTheSwitchIsOn(): void
    {
        $this->assertTrue(new EnvVoiceFeature(true)->isEnabled());
    }

    public function testIsDisabledWhenTheSwitchIsOff(): void
    {
        $this->assertFalse(new EnvVoiceFeature(false)->isEnabled());
    }
}
