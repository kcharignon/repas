<?php

namespace Repas\Tests\Repas\Infrastructure\Network;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Infrastructure\Network\DnsHostAddressResolver;
use Repas\Repas\Infrastructure\Network\PublicAddress;

/**
 * Le contrôle des adresses publiques que se partagent le lecteur de page Marmiton (ADR 0037) et le téléchargement d'images (ADR 0039).
 */
class PublicAddressTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function publicAddresses(): iterable
    {
        yield 'une IPv4 publique' => ['93.184.216.34'];
        yield 'une autre' => ['104.18.3.4'];
        yield 'au bord de 172.16/12 (172.15)' => ['172.15.0.1'];
        yield 'au bord de 172.16/12 (172.32)' => ['172.32.0.1'];
        yield 'au bord de 100.64/10 (100.128)' => ['100.128.0.1'];
        yield 'une IPv6 publique' => ['2606:4700::6810:84e5'];
        yield 'une IPv6 publique de Google' => ['2001:4860:4860::8888'];
        yield 'NAT64 vers une IPv4 publique' => ['64:ff9b::808:808'];
    }

    #[DataProvider('publicAddresses')]
    public function testAPublicAddressIsPublic(string $address): void
    {
        $this->assertTrue(PublicAddress::isPublic($address));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPublicAddresses(): iterable
    {
        yield 'loopback' => ['127.0.0.1'];
        yield 'le 127/8' => ['127.200.3.4'];
        yield 'privée 10/8' => ['10.0.0.5'];
        yield 'privée 172.16/12' => ['172.16.0.1'];
        yield 'privée 172.31' => ['172.31.255.254'];
        yield 'privée 192.168/16' => ['192.168.0.1'];
        yield 'lien local' => ['169.254.1.1'];
        yield 'les métadonnées des nuages' => ['169.254.169.254'];
        yield 'partagée 100.64/10' => ['100.64.0.1'];
        yield 'adresse nulle' => ['0.0.0.0'];
        yield '0/8' => ['0.1.2.3'];
        yield 'multicast' => ['224.0.0.1'];
        yield 'multicast, la fin' => ['239.255.255.255'];
        yield 'diffusion' => ['255.255.255.255'];
        yield 'réservée 240/4' => ['240.0.0.1'];
        yield 'documentation 192.0.2/24' => ['192.0.2.10'];
        yield 'documentation 198.51.100/24' => ['198.51.100.10'];
        yield 'documentation 203.0.113/24' => ['203.0.113.10'];
        yield 'test de performance 198.18/15' => ['198.18.0.1'];
        yield 'protocoles IETF 192.0.0/24' => ['192.0.0.8'];
        yield 'loopback IPv6' => ['::1'];
        yield 'l\'adresse nulle IPv6' => ['::'];
        yield 'lien local IPv6' => ['fe80::1'];
        yield 'privée IPv6 fc00::/7' => ['fc00::1'];
        yield 'privée IPv6 fd00' => ['fd12:3456:789a::1'];
        yield 'multicast IPv6' => ['ff02::1'];
        yield 'multicast IPv6 global' => ['ff0e::1'];
        yield 'site-local IPv6 (fec0::/10)' => ['fec0::1'];
        yield 'IPv4 écrite en IPv6 (loopback)' => ['::ffff:127.0.0.1'];
        yield 'IPv4 écrite en IPv6 (publique : refusée par prudence)' => ['::ffff:8.8.8.8'];
        yield 'NAT64 vers loopback' => ['64:ff9b::7f00:1'];
        yield 'NAT64 vers une adresse privée' => ['64:ff9b::a00:5'];
        yield 'NAT64 vers les métadonnées' => ['64:ff9b::a9fe:a9fe'];
        yield '6to4 vers loopback' => ['2002:7f00:1::1'];
        yield 'documentation IPv6' => ['2001:db8::1'];
        yield 'ce qui n\'est pas une adresse' => ['exemple.org'];
        yield 'une chaîne vide' => [''];
        yield 'une adresse mal écrite' => ['93.184.216'];
        yield 'un octet de trop' => ['93.184.216.256'];
        yield 'écrite en octal' => ['0177.0.0.1'];
        yield 'écrite en entier' => ['2130706433'];
        yield 'écrite en hexadécimal' => ['0x7f.0.0.1'];
    }

    #[DataProvider('nonPublicAddresses')]
    public function testAnythingElseIsNotPublic(string $address): void
    {
        $this->assertFalse(PublicAddress::isPublic($address));
    }

    public function testTheSystemResolverRendersTheAddressesOfAName(): void
    {
        // Given : `localhost` et une adresse écrite en chiffres se résolvent sans DNS
        $resolver = new DnsHostAddressResolver();

        // When / Then
        $this->assertContains('127.0.0.1', $resolver->resolve('localhost'));
        $this->assertContains('127.0.0.1', $resolver->resolveAll('localhost'));
        $this->assertSame(['93.184.216.34'], $resolver->resolve('93.184.216.34'));
        $this->assertSame(['93.184.216.34'], $resolver->resolveAll('93.184.216.34'));
        $this->assertSame([], $resolver->resolve('::1'), 'Une adresse IPv6 n\'est pas une adresse IPv4.');
        $this->assertSame([], $resolver->resolve(''));
        $this->assertSame([], $resolver->resolveAll(''));
        $this->assertSame([], $resolver->resolveAll('ce-nom-n-existe-pas.invalid'));
    }
}
