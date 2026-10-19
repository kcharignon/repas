<?php

namespace Repas\Tests\User\Infrastructure\AppRelease;


use PHPUnit\Framework\TestCase;
use Repas\User\Infrastructure\AppRelease\EnvAppReleaseProvider;

class EnvAppReleaseProviderTest extends TestCase
{
    public function testReadsBothVersions(): void
    {
        // When
        $release = new EnvAppReleaseProvider('0.1.10', '0.1.9')->current();

        // Then
        $this->assertSame('0.1.10', $release->latest?->toString());
        $this->assertSame('0.1.9', $release->minimum?->toString());
    }

    public function testEmptyOrAbsentVariablesAreUnknown(): void
    {
        // When : variables vides (le défaut) ou absentes du serveur
        $empty = new EnvAppReleaseProvider('', '')->current();
        $absent = new EnvAppReleaseProvider(null, null)->current();

        // Then : personne ne sera invité à mettre à jour
        $this->assertNull($empty->latest);
        $this->assertNull($empty->minimum);
        $this->assertNull($absent->latest);
        $this->assertNull($absent->minimum);
    }

    public function testAMalformedVariableIsUnknownNeverAnError(): void
    {
        // When : une faute de frappe sur le serveur
        $release = new EnvAppReleaseProvider('v0.1.10', '0.1')->current();

        // Then
        $this->assertNull($release->latest);
        $this->assertNull($release->minimum);
    }

    public function testSpacesAroundAVersionAreIgnored(): void
    {
        // When
        $release = new EnvAppReleaseProvider(" 0.1.10\n", '')->current();

        // Then
        $this->assertSame('0.1.10', $release->latest?->toString());
    }
}
