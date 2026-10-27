<?php

namespace Repas\Tests\Shared\Infrastructure\Http;


use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Tous les plafonds de débit « par adresse » comptent par la clé commune (`ClientAddressKey`) : une adresse lue par `getClientIp()` n'est
 * jamais passée telle quelle à un limiteur, sans quoi une adresse IPv6 changeant dans son préfixe /64 aurait un compteur par adresse.
 * Le plafond de connexion est le limiteur de l'application, pas celui, par défaut, du pare-feu.
 */
class LimitersCountByAddressKeyTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }

    public function testNoSourceFileReadsTheClientAddressWithoutTheCommonKey(): void
    {
        // Given
        $files = new Finder()->files()->in(self::root() . '/src')->name('*.php');

        // When
        $uses = [];
        foreach ($files as $file) {
            foreach (explode("\n", $file->getContents()) as $number => $line) {
                if (str_contains($line, 'getClientIp(') && !str_contains($line, 'ClientAddressKey::of(')) {
                    $uses[] = sprintf('%s:%d', $file->getRelativePathname(), $number + 1);
                }
            }
        }

        // Then
        $this->assertSame([], $uses, 'Adresse lue sans `ClientAddressKey::of(` : ' . implode(', ', $uses));
    }

    public function testTheLoginThrottlingUsesTheApplicationLimiter(): void
    {
        // Given
        $security = Yaml::parseFile(self::root() . '/config/packages/security.yaml');

        // Then
        $this->assertSame(
            'Repas\User\Infrastructure\Security\LoginRateLimiter',
            $security['security']['firewalls']['login']['login_throttling']['limiter'] ?? null,
        );
    }
}
