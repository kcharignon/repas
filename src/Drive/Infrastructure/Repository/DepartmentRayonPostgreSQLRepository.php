<?php

namespace Repas\Drive\Infrastructure\Repository;


use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Drive\Domain\Interface\DepartmentRayonRepository;
use Repas\Drive\Domain\Model\DepartmentRayonChoice;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Infrastructure\Entity\DepartmentRayon as DepartmentRayonEntity;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;

/**
 * Écriture en SQL direct, comme les produits : un lot coûte une requête, quelle que soit sa taille, et deux requêtes
 * concurrentes s'additionnent sans se perdre (le compteur est incrémenté par la base).
 */
readonly class DepartmentRayonPostgreSQLRepository extends PostgreSQLRepository implements DepartmentRayonRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, DepartmentRayonEntity::class);
    }

    public function recordChoices(Retailer $retailer, array $choices): void
    {
        // Une ligne par couple dans l'instruction : PostgreSQL refuse de modifier deux fois la même ligne dans un seul INSERT
        /** @var array<string, array<int, int>> $counts nombre de choix par rayon de Repas, puis par rayon de l'enseigne */
        $counts = [];
        foreach ($choices as $choice) {
            $counts[$choice->departmentSlug][$choice->rayonId] = ($counts[$choice->departmentSlug][$choice->rayonId] ?? 0) + 1;
        }

        $params = [];
        $rows = 0;
        foreach ($counts as $departmentSlug => $byRayon) {
            foreach ($byRayon as $rayonId => $count) {
                array_push($params, $retailer->value, (string) $departmentSlug, $rayonId, $count);
                $rows++;
            }
        }
        if ($rows === 0) {
            return;
        }
        $tuples = implode(', ', array_fill(0, $rows, '(?, ?, ?, ?)'));

        $this->connection()->executeStatement(
            sprintf(
                'INSERT INTO drive_department_rayon (retailer, department_slug, rayon_id, chosen_count) VALUES %s '
                . 'ON CONFLICT (retailer, department_slug, rayon_id) DO UPDATE SET chosen_count = drive_department_rayon.chosen_count + EXCLUDED.chosen_count',
                $tuples,
            ),
            $params,
        );
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
