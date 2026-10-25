<?php

namespace Repas\Voice\Infrastructure\Repository;


use Doctrine\Persistence\ManagerRegistry;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;
use Repas\Voice\Domain\Interface\UserVoiceDataRepository;
use Repas\Voice\Infrastructure\Entity\VoiceLink as VoiceLinkEntity;

/**
 * Suppression en SQL direct, sans passer par l'ORM ni par son identity map : une seule requête efface les trois tables.
 */
readonly class UserVoiceDataPostgreSQLRepository extends PostgreSQLRepository implements UserVoiceDataRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, VoiceLinkEntity::class);
    }

    /**
     * Une seule instruction pour les trois tables (budget SQL de `supprimer-compte`) : les liaisons du compte sont effacées et rendent
     * leurs identifiants, par lesquels leurs jetons sont effacés à leur tour (`voice_access_grant` n'a pas de colonne `user_id`), puis les
     * codes d'autorisation du compte. PostgreSQL exécute toujours jusqu'au bout une instruction qui modifie des données dans un `WITH`,
     * même si la requête principale ne lit pas son résultat.
     *
     * Ne pas lire une liaison, un code ou un jeton par l'ORM avant d'appeler cette méthode dans la même requête : l'entité resterait dans
     * l'identity map après sa suppression. La suppression d'un compte n'en charge aucun.
     */
    public function deleteByUser(string $userId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'WITH erased_links AS (DELETE FROM voice_link WHERE user_id = :userId RETURNING id), '
            . 'erased_grants AS (DELETE FROM voice_access_grant WHERE link_id IN (SELECT id FROM erased_links)) '
            . 'DELETE FROM voice_authorization_code WHERE user_id = :userId',
            ['userId' => $userId],
        );
    }
}
