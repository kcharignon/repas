<?php

namespace Repas\Drive\Infrastructure\Repository;


use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Drive\Domain\Interface\RayonVoteRepository;
use Repas\Drive\Domain\Model\RayonVote;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Infrastructure\Entity\RayonVote as RayonVoteEntity;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;

/**
 * Écriture en SQL direct, comme les produits : un lot coûte une requête, quelle que soit sa taille, et deux requêtes concurrentes pour le
 * même vote n'en font qu'un (la clé primaire). Le plafond de couples par compte est dans la même instruction : elle ne lit les votes du
 * compte que pour les compter, et n'insère que ce qui reste de la place, sans compter les couples déjà votés.
 */
readonly class RayonVotePostgreSQLRepository extends PostgreSQLRepository implements RayonVoteRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, RayonVoteEntity::class);
    }

    public function record(string $userId, Retailer $retailer, array $votes): void
    {
        // Un couple une seule fois dans l'instruction
        /** @var array<string, array<int, true>> $pairs rayons de l'enseigne votés, par rayon de Repas */
        $pairs = [];
        foreach ($votes as $vote) {
            $pairs[$vote->departmentSlug][$vote->rayonId] = true;
        }

        $params = [];
        $rows = 0;
        foreach ($pairs as $departmentSlug => $rayons) {
            foreach (array_keys($rayons) as $rayonId) {
                array_push($params, $userId, $retailer->value, (string) $departmentSlug, $rayonId);
                $rows++;
            }
        }
        if ($rows === 0) {
            return;
        }
        $tuples = implode(', ', array_fill(0, $rows, '(?::varchar, ?::varchar, ?::varchar, ?::integer)'));
        array_push($params, RayonVote::MAX_PER_USER_AND_RETAILER, $userId, $retailer->value);

        $this->connection()->executeStatement(
            sprintf(
                'INSERT INTO drive_rayon_vote (user_id, retailer, department_slug, rayon_id) '
                . 'SELECT v.user_id, v.retailer, v.department_slug, v.rayon_id FROM (VALUES %s) AS v (user_id, retailer, department_slug, rayon_id) '
                . 'WHERE NOT EXISTS (SELECT 1 FROM drive_rayon_vote x WHERE x.user_id = v.user_id AND x.retailer = v.retailer '
                . 'AND x.department_slug = v.department_slug AND x.rayon_id = v.rayon_id) '
                . 'LIMIT GREATEST(0, ?::integer - (SELECT count(*) FROM drive_rayon_vote WHERE user_id = ? AND retailer = ?)) '
                . 'ON CONFLICT DO NOTHING',
                $tuples,
            ),
            $params,
        );
    }

    public function deleteByUser(string $userId): void
    {
        $this->connection()->executeStatement('DELETE FROM drive_rayon_vote WHERE user_id = ?', [$userId]);
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
