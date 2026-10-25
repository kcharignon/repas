<?php

namespace Repas\Tests\Voice\Infrastructure\Http;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Voice\Infrastructure\Http\ClientAddressKey;

/**
 * La clé d'un plafond « par adresse » (revue de sécurité du 2026-10-25) : une adresse IPv6 compte par son préfixe /64, une IPv4 mappée en IPv6
 * est l'IPv4, une adresse invalide a une clé stable et sûre.
 */
class ClientAddressKeyTest extends TestCase
{
    #[DataProvider('addresses')]
    public function testEachKindOfAddressGivesItsKey(?string $address, string $expected): void
    {
        $this->assertSame($expected, ClientAddressKey::of($address));
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function addresses(): iterable
    {
        // IPv4 : inchangée
        yield 'IPv4' => ['203.0.113.7', '203.0.113.7'];
        yield 'IPv4 extrême' => ['255.255.255.255', '255.255.255.255'];
        yield 'IPv4 de bouclage' => ['127.0.0.1', '127.0.0.1'];

        // IPv6 : le préfixe /64, quelle que soit l'écriture
        yield 'IPv6 complète' => ['2001:0db8:0001:0000:0000:0000:0000:0001', '2001:db8:1::/64'];
        yield 'IPv6 abrégée' => ['2001:db8:1::1', '2001:db8:1::/64'];
        yield 'IPv6 majuscules' => ['2001:DB8:1::ABCD', '2001:db8:1::/64'];
        yield 'IPv6 dont la partie haute est pleine' => ['2001:db8:1:2:3:4:5:6', '2001:db8:1:2::/64'];
        yield 'IPv6 dont seul le suffixe change' => ['2001:db8:1:2:ffff:ffff:ffff:ffff', '2001:db8:1:2::/64'];
        yield 'IPv6 d\'un autre /64' => ['2001:db8:2::1', '2001:db8:2::/64'];
        yield 'IPv6 de bouclage' => ['::1', '::/64'];
        yield 'IPv6 non spécifiée' => ['::', '::/64'];
        yield 'IPv6 de lien local' => ['fe80::1234:5678:9abc:def0', 'fe80::/64'];

        // IPv4 mappée en IPv6 : l'IPv4
        yield 'IPv4 mappée, forme décimale' => ['::ffff:203.0.113.7', '203.0.113.7'];
        yield 'IPv4 mappée, forme hexadécimale' => ['::ffff:cb00:7107', '203.0.113.7'];
        yield 'IPv4 mappée, complète et en majuscules' => ['0:0:0:0:0:FFFF:CB00:7107', '203.0.113.7'];
        yield 'IPv4 mappée de bouclage' => ['::ffff:127.0.0.1', '127.0.0.1'];

        // Absente ou invalide : une clé stable qui ne reprend jamais la chaîne reçue
        yield 'absente' => [null, 'unknown'];
        yield 'vide' => ['', 'unknown'];
        yield 'texte' => ['pas une adresse', 'unknown'];
        yield 'IPv4 incomplète' => ['203.0.113', 'unknown'];
        yield 'IPv4 hors limites' => ['256.1.1.1', 'unknown'];
        yield 'IPv6 trop longue' => ['2001:db8:1:2:3:4:5:6:7', 'unknown'];
        yield 'IPv6 avec deux abréviations' => ['2001::db8::1', 'unknown'];
        yield 'IPv6 avec une zone' => ['fe80::1%eth0', 'unknown'];
        yield 'avec un préfixe' => ['2001:db8:1::/64', 'unknown'];
        yield 'avec un port' => ['203.0.113.7:8080', 'unknown'];
        yield 'injection' => ["203.0.113.7\n'; DROP TABLE x; --", 'unknown'];
    }

    public function testAllTheAddressesOfAnIpv6PrefixShareOneKeyAndAnotherPrefixHasItsOwn(): void
    {
        // Given : 70 adresses d'un même /64
        $keys = [];
        for ($i = 1; $i <= 70; $i++) {
            $keys[ClientAddressKey::of(sprintf('2001:db8:1::%x', $i))] = true;
        }

        // Then : une seule clé ; un autre /64 en a une autre ; deux adresses IPv4 voisines restent distinctes
        $this->assertSame(['2001:db8:1::/64'], array_keys($keys));
        $this->assertNotSame(ClientAddressKey::of('2001:db8:1::1'), ClientAddressKey::of('2001:db8:2::1'));
        $this->assertNotSame(ClientAddressKey::of('203.0.113.7'), ClientAddressKey::of('203.0.113.8'));
    }

    public function testAnIpv4AndItsMappedIpv6FormShareOneKey(): void
    {
        $this->assertSame(ClientAddressKey::of('203.0.113.7'), ClientAddressKey::of('::ffff:203.0.113.7'));
        $this->assertNotSame(ClientAddressKey::of('203.0.113.7'), ClientAddressKey::of('::ffff:203.0.113.8'));
    }

    public function testTheKeyNeverRepeatsAnInvalidInput(): void
    {
        $this->assertStringNotContainsString('DROP', ClientAddressKey::of("1.2.3.4'; DROP TABLE x; --"));
    }
}
