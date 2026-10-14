<?php

namespace Repas\User\Domain\Interface;

use DateTimeImmutable;
use Repas\User\Domain\Model\RefreshToken;

interface RefreshTokenRepository
{
    /**
     * Le jeton dont le secret a ce hachage, ou `null` s'il est inconnu (jamais émis, supprimé avec sa famille, purgé).
     */
    public function findOneByTokenHash(string $tokenHash): ?RefreshToken;

    public function save(RefreshToken $refreshToken): void;

    /**
     * Supprime tous les jetons d'une famille, utilisés ou non : la connexion est révoquée.
     */
    public function deleteFamily(string $familyId): void;

    /**
     * Ne garde que les `$keep` familles les plus récemment actives de l'utilisateur et supprime les autres.
     */
    public function deleteOldestFamiliesOfUser(string $userId, int $keep): void;

    /**
     * Supprime les jetons dont le jeton lui-même ou la famille a expiré.
     *
     * @return int nombre de jetons supprimés
     */
    public function deleteExpired(DateTimeImmutable $now): int;
}
