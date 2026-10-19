<?php

namespace Repas\User\Infrastructure\Repository;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;
use Repas\User\Domain\Interface\UserAppVersionRepository;
use Repas\User\Domain\Model\UserAppVersion;
use Repas\User\Infrastructure\Entity\UserAppVersion as UserAppVersionEntity;

/**
 * Écriture en SQL direct (DRV-637) : une requête, sans lecture préalable, et sans passer par l'identity map (la ligne n'est
 * jamais hydratée).
 */
readonly class UserAppVersionPostgreSQLRepository extends PostgreSQLRepository implements UserAppVersionRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, UserAppVersionEntity::class);
    }

    /**
     * Une seule instruction : la ligne n'est insérée (ou remplacée) que si le compte existe, et `FOR KEY SHARE` verrouille la ligne
     * du compte jusqu'à la fin de la transaction. Une suppression du compte qui arrive pendant l'écriture attend donc sa fin, puis
     * efface la ligne (`DeleteUserHandler` supprime le compte AVANT ses versions) ; une écriture qui arrive pendant la suppression
     * attend sa fin et ne trouve plus le compte : rien n'est écrit. Sans ce verrou, la ligne pourrait renaître après la purge.
     */
    public function save(UserAppVersion $appVersion): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO user_app_version (user_id, platform, version, seen_at) '
            . 'SELECT u.id, CAST(:platform AS VARCHAR(16)), CAST(:version AS VARCHAR(20)), CAST(:seenAt AS TIMESTAMP(0) WITHOUT TIME ZONE) '
            . 'FROM "user" u WHERE u.id = :userId FOR KEY SHARE '
            . 'ON CONFLICT (user_id, platform) DO UPDATE SET version = EXCLUDED.version, seen_at = EXCLUDED.seen_at',
            [
                'userId' => $appVersion->getUserId(),
                'platform' => $appVersion->getPlatform()->value,
                'version' => $appVersion->getVersion()->toString(),
                'seenAt' => $appVersion->getSeenAt(),
            ],
            ['seenAt' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function deleteAllOfUser(string $userId): void
    {
        $this->connection()->executeStatement('DELETE FROM user_app_version WHERE user_id = :userId', ['userId' => $userId]);
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
