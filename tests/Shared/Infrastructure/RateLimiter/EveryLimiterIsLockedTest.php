<?php

namespace Repas\Tests\Shared\Infrastructure\RateLimiter;


use ReflectionProperty;
use Repas\Shared\Infrastructure\RateLimiter\BoundedLockFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Yaml\Yaml;

/**
 * Chaque limiteur déclaré dans config/packages/rate_limiter.yaml lit et écrit son compteur sous verrou, avec la fabrique de l'application
 * (`BoundedLockFactory`, un nombre borné de verrous) : un limiteur sans `lock_factory` prendrait la fabrique par défaut du framework.
 */
class EveryLimiterIsLockedTest extends KernelTestCase
{
    public function testEveryRateLimiterUsesTheBoundedLockFactory(): void
    {
        // Given
        $config = Yaml::parseFile(dirname(__DIR__, 4) . '/config/packages/rate_limiter.yaml');
        $names = array_keys($config['framework']['rate_limiter']);
        self::bootKernel();

        // When
        $locked = [];
        foreach ($names as $name) {
            $limiter = static::getContainer()->get('limiter.' . $name);
            $this->assertInstanceOf(RateLimiterFactory::class, $limiter, $name);
            $locked[$name] = new ReflectionProperty(RateLimiterFactory::class, 'lockFactory')->getValue($limiter);
        }

        // Then
        $this->assertNotSame([], $names);
        foreach ($locked as $name => $lockFactory) {
            $this->assertInstanceOf(BoundedLockFactory::class, $lockFactory, "Le limiteur $name n'a pas la fabrique de verrous de l'application.");
        }
    }
}
