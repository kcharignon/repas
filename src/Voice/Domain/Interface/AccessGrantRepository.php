<?php

namespace Repas\Voice\Domain\Interface;


use DateTimeImmutable;
use Repas\Voice\Domain\Model\AccessGrant;

/**
 * Les paires de jetons (ADR 0029 décision 6), cherchées par le haché du jeton de rafraîchissement. Résoudre un jeton d'accès est une
 * lecture : `AccessGrantLoader`.
 */
interface AccessGrantRepository
{
    public function add(AccessGrant $grant): void;

    public function findOneByRefreshHash(string $refreshHash): ?AccessGrant;

    /**
     * Consomme le jeton de rafraîchissement, ATOMIQUEMENT : n'écrit que s'il n'est pas encore utilisé, et dit si c'est cette requête qui
     * l'a consommé. Deux rafraîchissements simultanés avec le même jeton ne réussissent jamais tous les deux (le second est un rejeu).
     */
    public function consumeRefresh(string $refreshHash, DateTimeImmutable $now): bool;

    /**
     * Efface toutes les paires de la liaison, consommées ou non : plus aucun de ses jetons ne sert.
     */
    public function deleteByLink(string $linkId): void;

    /**
     * Efface les paires de la liaison dont le jeton de rafraîchissement a expiré : elles ne servent plus ni à rafraîchir ni à
     * reconnaître un rejeu, et leur jeton d'accès a expiré avant elles.
     */
    public function deleteExpiredOfLink(string $linkId, DateTimeImmutable $now): void;
}
