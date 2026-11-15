<?php

namespace Repas\Tests\Voice\Infrastructure\Feature;


use PHPUnit\Framework\TestCase;
use Repas\Voice\Infrastructure\Feature\EnvVoiceTraceFeature;

class EnvVoiceTraceFeatureTest extends TestCase
{
    public function testIsEnabledWhenTheSwitchIsOn(): void
    {
        $this->assertTrue(new EnvVoiceTraceFeature(true)->isEnabled());
    }

    public function testIsDisabledWhenTheSwitchIsOff(): void
    {
        $this->assertFalse(new EnvVoiceTraceFeature(false)->isEnabled());
    }
}
