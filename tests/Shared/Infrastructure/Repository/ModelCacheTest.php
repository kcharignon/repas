<?php

namespace Repas\Tests\Shared\Infrastructure\Repository;


use Repas\Shared\Infrastructure\Repository\ModelCache;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ModelCacheTest extends KernelTestCase
{
    public function testResetEmptiesTheCache(): void
    {
        // Given
        $cache = new ModelCache();
        $unit = new UnitBuilder()->isGramme()->build();
        $cache->setModelCache($unit);

        // When
        $cache->reset();

        // Then
        $this->assertFalse($cache->isCachedExists($unit::class, $unit->getId()));
        $this->assertNull($cache->getModelCache($unit::class, $unit->getId()));
    }

    public function testTheKernelResetsTheCacheBetweenTwoRequests(): void
    {
        // Given : en mode worker, le conteneur survit à la requête et le kernel appelle `services_resetter`
        self::bootKernel();
        $cache = self::getContainer()->get(ModelCache::class);
        $unit = new UnitBuilder()->isGramme()->build();
        $cache->setModelCache($unit);

        // When
        self::getContainer()->get('services_resetter')->reset();

        // Then
        $this->assertFalse($cache->isCachedExists($unit::class, $unit->getId()));
    }
}
