<?php

namespace Repas\Drive\Infrastructure\Repository;


use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Repas\Drive\Domain\Interface\RetailerPreferenceRepository;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\RetailerPreference;

/**
 * SQL direct, une requête par opération : la ligne n'est jamais dans l'identity map, donc une lecture ne rend jamais une valeur périmée
 * après une écriture, et deux premières écritures simultanées d'un même compte (le drive habituel et la dernière enseigne) ne se heurtent
 * pas sur la clé (`INSERT … ON CONFLICT`). Une valeur d'enseigne que ce code ne connaît plus (retirée du registre) se lit comme absente.
 */
readonly class RetailerPreferencePostgreSQLRepository implements RetailerPreferenceRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findOneByUser(string $userId): ?RetailerPreference
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT default_retailer, last_used_retailer, updated_at FROM drive_retailer_preference WHERE user_id = :user',
            ['user' => $userId],
        );
        if ($row === false) {
            return null;
        }

        return RetailerPreference::load([
            'user_id' => $userId,
            'default_retailer' => $row['default_retailer'] === null ? null : Retailer::tryFrom($row['default_retailer']),
            'last_used_retailer' => $row['last_used_retailer'] === null ? null : Retailer::tryFrom($row['last_used_retailer']),
            'updated_at' => new DateTimeImmutable($row['updated_at']),
        ]);
    }

    public function save(RetailerPreference $preference): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO drive_retailer_preference (user_id, default_retailer, last_used_retailer, updated_at)'
            . ' VALUES (:user, :default, :lastUsed, :updatedAt)'
            . ' ON CONFLICT (user_id) DO UPDATE SET default_retailer = EXCLUDED.default_retailer,'
            . ' last_used_retailer = EXCLUDED.last_used_retailer, updated_at = EXCLUDED.updated_at',
            [
                'user' => $preference->getUserId(),
                'default' => $preference->getDefaultRetailer()?->value,
                'lastUsed' => $preference->getLastUsedRetailer()?->value,
                'updatedAt' => $preference->getUpdatedAt()->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function deleteByUser(string $userId): void
    {
        $this->entityManager->getConnection()->executeStatement('DELETE FROM drive_retailer_preference WHERE user_id = :user', ['user' => $userId]);
    }
}
