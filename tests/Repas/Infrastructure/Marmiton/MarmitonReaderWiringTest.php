<?php

namespace Repas\Tests\Repas\Infrastructure\Marmiton;


use ReflectionProperty;
use Repas\Repas\Domain\Interface\MarmitonPageReader;
use Repas\Repas\Infrastructure\Marmiton\DnsHostAddressResolver;
use Repas\Repas\Infrastructure\Marmiton\HostAddressResolver;
use Repas\Repas\Infrastructure\Marmiton\MarmitonHttpPageReader;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le câblage réel du lecteur de page Marmiton (MAR-001) : le port est servi par l'adaptateur HTTP, qui résout le nom par le système.
 * Aucun de ces essais n'atteint Marmiton ni le réseau : `localhost` et une adresse écrite en chiffres se résolvent sans DNS.
 */
class MarmitonReaderWiringTest extends KernelTestCase
{
    public function testThePortIsServedByTheHttpAdapterWithTheSystemResolver(): void
    {
        // Given
        self::bootKernel();

        // When
        $reader = self::getContainer()->get(MarmitonPageReader::class);

        // Then
        $this->assertInstanceOf(MarmitonHttpPageReader::class, $reader);
        $this->assertInstanceOf(DnsHostAddressResolver::class, (new ReflectionProperty(MarmitonHttpPageReader::class, 'resolver'))->getValue($reader));
        $this->assertInstanceOf(DnsHostAddressResolver::class, self::getContainer()->get(HostAddressResolver::class));
    }

    public function testTheSystemResolverRendersTheIpv4AddressesOfAName(): void
    {
        // Given
        $resolver = new DnsHostAddressResolver();

        // When / Then
        $this->assertContains('127.0.0.1', $resolver->resolve('localhost'));
        $this->assertSame(['93.184.216.34'], $resolver->resolve('93.184.216.34'));
        $this->assertSame([], $resolver->resolve('::1'), 'Une adresse IPv6 n\'est pas une adresse IPv4.');
        $this->assertSame([], $resolver->resolve(''));
    }
}
