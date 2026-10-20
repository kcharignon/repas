<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Infrastructure\Entity\Unit;
use Repas\Shared\Application\Interface\UnitSlugResolver;

/**
 * Dans `Infrastructure/Loader` parce que les requêtes Doctrine n'ont pas d'autre place hors des repositories (CLAUDE.md) ;
 * `Resolver` et non `Loader` pour la raison dite sur le port.
 */
final readonly class UnitSlugPostgreSQLResolver implements UnitSlugResolver
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function existingSlugs(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('u.slug AS slug')
            ->from(Unit::class, 'u')
            ->where('u.slug IN (:slugs)')
            ->setParameter('slugs', array_values(array_unique($slugs)))
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(static fn(array $row): string => (string) $row['slug'], $rows));
    }
}
