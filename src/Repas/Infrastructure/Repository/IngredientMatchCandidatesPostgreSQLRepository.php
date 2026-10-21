<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Interface\IngredientMatchCandidates;
use Repas\Repas\Domain\Model\IngredientCandidate;
use Repas\Repas\Infrastructure\Entity\Ingredient as IngredientEntity;
use Repas\Repas\Infrastructure\Visibility\IngredientVisibility;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;

/**
 * Une requête, sans hydrater d'entité ni de modèle : la règle de visibilité est `IngredientVisibility::visibleBy`, celle de toutes les
 * lectures d'ingrédients d'un utilisateur : les siens, et seulement les siens.
 */
readonly class IngredientMatchCandidatesPostgreSQLRepository extends PostgreSQLRepository implements IngredientMatchCandidates
{
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, IngredientEntity::class);
    }

    public function visibleTo(string $userId): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('i.slug AS slug', 'i.name AS name', 'i.departmentSlug AS departmentSlug', 'i.defaultPurchaseUnitSlug AS purchaseUnitSlug')
            ->addSelect('i.compatibleUnitSlugs AS compatibleUnitSlugs', 'i.adoptedFrom AS adoptedFrom')
            ->from(IngredientEntity::class, 'i')
            ->where(IngredientVisibility::visibleBy('i', 'viewer'))
            ->setParameter('viewer', $userId)
            ->orderBy('i.slug', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn(array $row): IngredientCandidate => new IngredientCandidate(
                slug: $row['slug'],
                name: $row['name'],
                departmentSlug: $row['departmentSlug'],
                purchaseUnitSlug: $row['purchaseUnitSlug'],
                compatibleUnitSlugs: array_values($row['compatibleUnitSlugs']),
                adoptedFrom: $row['adoptedFrom'],
            ),
            $rows,
        );
    }
}
