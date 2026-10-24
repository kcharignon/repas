<?php

namespace Repas\Tests\User\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\User\Domain\Model\AppRelease;
use Repas\User\Domain\Model\AppVersion;

class AppReleaseTest extends TestCase
{
    #[DataProvider('cases')]
    public function testAnInstalledVersionIsOutdatedWhenOlderThanTheLatestOrTheMinimum(?string $latest, ?string $minimum, ?string $installed, bool $outdated): void
    {
        // Given
        $release = AppRelease::fromSettings($latest, $minimum);

        // When
        $result = $release->isOutdated($installed === null ? null : AppVersion::fromString($installed));

        // Then
        $this->assertSame($outdated, $result);
    }

    /**
     * @return iterable<string, array{?string, ?string, ?string, bool}>
     */
    public static function cases(): iterable
    {
        // dernière, minimale, installée, à mettre à jour
        yield 'plus ancienne que la dernière' => ['0.1.10', null, '0.1.8', true];
        yield '0.1.9 plus ancienne que 0.1.10 (pas une comparaison de textes)' => ['0.1.10', null, '0.1.9', true];
        yield '0.1.10 plus récente que 0.1.9' => ['0.1.9', null, '0.1.10', false];
        yield '1.0.0 plus récente que 0.99.99' => ['0.99.99', null, '1.0.0', false];
        yield '0.99.99 plus ancienne que 1.0.0' => ['1.0.0', null, '0.99.99', true];
        yield 'la dernière elle-même' => ['0.1.10', null, '0.1.10', false];
        yield 'plus récente que la dernière connue (publiée avant le réglage)' => ['0.1.10', null, '0.1.11', false];
        yield 'version inconnue (navigateur)' => ['0.1.10', '0.1.9', null, false];
        yield 'serveur sans réglage' => [null, null, '0.0.1', false];
        yield 'réglages vides' => ['', '', '0.0.1', false];
        yield 'réglages faits d\'espaces' => ['  ', ' ', '0.0.1', false];
        yield 'réglage avec espaces autour' => [' 0.1.10 ', null, '0.1.8', true];
        yield 'réglage mal formé : ignoré, jamais une erreur' => ['0.1.10-dev', 'dix', '0.1.8', false];
        yield 'seulement la minimale, au-dessus' => [null, '0.1.9', '0.1.9', false];
        yield 'seulement la minimale, en dessous' => [null, '0.1.9', '0.1.8', true];
        yield 'au-dessus de la minimale, en dessous de la dernière' => ['0.1.10', '0.1.8', '0.1.9', true];
        yield 'minimale plus haute que la dernière (réglage incohérent)' => ['0.1.8', '0.1.10', '0.1.9', true];
    }

    public function testTheSettingsAreKeptAsVersions(): void
    {
        // When
        $release = AppRelease::fromSettings(' 0.1.10', '0.1.9 ');

        // Then
        $this->assertSame('0.1.10', $release->latest?->toString());
        $this->assertSame('0.1.9', $release->minimum?->toString());
    }

    public function testEmptyOrMalformedSettingsAreUnknown(): void
    {
        // When
        $release = AppRelease::fromSettings('', '1.0');

        // Then
        $this->assertNull($release->latest);
        $this->assertNull($release->minimum);
    }
}
