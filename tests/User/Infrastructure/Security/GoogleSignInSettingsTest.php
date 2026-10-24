<?php

namespace Repas\Tests\User\Infrastructure\Security;


use PHPUnit\Framework\TestCase;
use Repas\User\Infrastructure\Security\GoogleSignInSettings;

class GoogleSignInSettingsTest extends TestCase
{
    public function testAClientIdTurnsTheFeatureOn(): void
    {
        // When
        $settings = new GoogleSignInSettings(' 123456-repastest.apps.googleusercontent.com ');

        // Then
        $this->assertTrue($settings->isEnabled());
        $this->assertSame('123456-repastest.apps.googleusercontent.com', $settings->clientId());
    }

    public function testAnEmptyOrBlankClientIdTurnsTheFeatureOff(): void
    {
        foreach (['', '   '] as $clientId) {
            // When
            $settings = new GoogleSignInSettings($clientId);

            // Then
            $this->assertFalse($settings->isEnabled());
            $this->assertSame('', $settings->clientId());
        }
    }
}
