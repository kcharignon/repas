<?php

namespace Repas\Tests\User\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\User\Domain\Exception\AppVersionException;
use Repas\User\Domain\Model\AppVersion;

class AppVersionTest extends TestCase
{
    #[DataProvider('validVersions')]
    public function testAcceptsThreeNumbersSeparatedByDots(string $version): void
    {
        // When
        $appVersion = AppVersion::fromString($version);

        // Then : retenue telle quelle
        $this->assertSame($version, $appVersion->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validVersions(): iterable
    {
        yield 'version courante' => ['0.1.8'];
        yield 'nombres à plusieurs chiffres' => ['0.1.10'];
        yield 'tout à zéro' => ['0.0.0'];
        yield 'zéro en tête' => ['0.1.08'];
        yield '20 caractères' => ['1.1.1234567890123456'];
    }

    #[DataProvider('invalidVersions')]
    public function testRefusesAnythingElse(string $version): void
    {
        // Then
        $this->expectExceptionObject(AppVersionException::invalidVersion());

        // When
        AppVersion::fromString($version);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidVersions(): iterable
    {
        yield 'vide' => [''];
        yield 'quatre nombres' => ['0.1.8.1'];
        yield 'deux nombres' => ['0.1'];
        yield 'un seul nombre' => ['1'];
        yield 'suffixe' => ['0.1.8-dev'];
        yield 'suffixe de debug' => ['0.0.0-dev'];
        yield 'préfixe v' => ['v0.1.8'];
        yield 'espace devant' => [' 0.1.8'];
        yield 'espace derrière' => ['0.1.8 '];
        yield 'saut de ligne final' => ["0.1.8\n"];
        yield 'nombre vide' => ['0..8'];
        yield 'point final' => ['0.1.8.'];
        yield 'nombre négatif' => ['0.-1.8'];
        yield 'chiffres non latins' => ['٠.١.٨'];
        yield 'virgule' => ['0,1,8'];
        yield '21 caractères' => ['1.1.12345678901234567'];
        yield 'balise' => ['<b>0.1.8</b>'];
    }

    public function testTheErrorIsABusinessRule(): void
    {
        // When
        $exception = AppVersionException::invalidVersion();

        // Then
        $this->assertSame('INVALID_APP_VERSION', $exception->getMessage());
        $this->assertSame(422, $exception->getCode());
    }

    #[DataProvider('orderedPairs')]
    public function testComparesNumberByNumberNeverAsText(string $older, string $newer): void
    {
        // Given
        $old = AppVersion::fromString($older);
        $new = AppVersion::fromString($newer);

        // Then
        $this->assertTrue($old->isOlderThan($new), "$older est plus ancienne que $newer");
        $this->assertFalse($new->isOlderThan($old), "$newer n'est pas plus ancienne que $older");
        $this->assertFalse($old->isEqual($new));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function orderedPairs(): iterable
    {
        yield '0.1.9 < 0.1.10 (le texte dirait le contraire)' => ['0.1.9', '0.1.10'];
        yield '0.99.99 < 1.0.0' => ['0.99.99', '1.0.0'];
        yield '0.1.8 < 0.1.9' => ['0.1.8', '0.1.9'];
        yield '0.9.0 < 0.10.0' => ['0.9.0', '0.10.0'];
        yield '9.0.0 < 10.0.0' => ['9.0.0', '10.0.0'];
        yield '0.1.99 < 0.2.0' => ['0.1.99', '0.2.0'];
        yield 'grands nombres' => ['0.0.999999999999999', '0.0.1000000000000000'];
    }

    public function testTheSameVersionIsNotOlder(): void
    {
        // Given
        $version = AppVersion::fromString('0.1.10');

        // Then
        $this->assertFalse($version->isOlderThan(AppVersion::fromString('0.1.10')));
        $this->assertTrue($version->isEqual(AppVersion::fromString('0.1.10')));
    }

    public function testALeadingZeroDoesNotChangeTheVersion(): void
    {
        // Then
        $this->assertTrue(AppVersion::fromString('0.1.08')->isEqual(AppVersion::fromString('0.1.8')));
        $this->assertFalse(AppVersion::fromString('0.1.08')->isOlderThan(AppVersion::fromString('0.1.8')));
    }

    public function testTryFromStringGivesNullInsteadOfAnError(): void
    {
        // Then
        $this->assertNull(AppVersion::tryFromString(null));
        $this->assertNull(AppVersion::tryFromString(''));
        $this->assertNull(AppVersion::tryFromString('0.1'));
        $this->assertNull(AppVersion::tryFromString('0.1.8-dev'));
        $this->assertSame('0.1.10', AppVersion::tryFromString('0.1.10')?->toString());
    }
}
