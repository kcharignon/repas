<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Shared\Application\Interface\IngredientNameLoader;

final readonly class IngredientNamePostgreSQLLoader implements IngredientNameLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function namesBySlug(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('i.slug AS slug', 'i.name AS name')
            ->from(Ingredient::class, 'i')
            ->where('i.slug IN (:slugs)')
            ->setParameter('slugs', array_values(array_unique($slugs)))
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'name', 'slug');
    }
}
