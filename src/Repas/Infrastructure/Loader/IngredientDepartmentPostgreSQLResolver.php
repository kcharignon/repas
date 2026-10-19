<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Shared\Application\Interface\IngredientDepartmentResolver;

/**
 * Dans `Infrastructure/Loader` parce que les requêtes Doctrine n'ont pas d'autre place hors des repositories (CLAUDE.md) ;
 * `Resolver` et non `Loader` pour la raison dite sur le port.
 */
final readonly class IngredientDepartmentPostgreSQLResolver implements IngredientDepartmentResolver
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function departmentSlugsByIngredient(array $ingredientSlugs): array
    {
        if ($ingredientSlugs === []) {
            return [];
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('i.slug AS slug', 'i.departmentSlug AS departmentSlug')
            ->from(Ingredient::class, 'i')
            ->where('i.slug IN (:slugs)')
            ->setParameter('slugs', array_values(array_unique($ingredientSlugs)))
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'departmentSlug', 'slug');
    }
}
