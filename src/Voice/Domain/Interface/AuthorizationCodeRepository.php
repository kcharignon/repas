<?php

namespace Repas\Voice\Domain\Interface;


use DateTimeImmutable;
use Repas\Voice\Domain\Model\AuthorizationCode;

/**
 * Les codes d'autorisation (ADR 0029 décision 6), cherchés par leur haché.
 */
interface AuthorizationCodeRepository
{
    public function add(AuthorizationCode $code): void;

    public function findOneByHash(string $codeHash): ?AuthorizationCode;

    /**
     * Consomme le code, ATOMIQUEMENT : n'écrit que s'il n'est pas encore utilisé, et dit si c'est cette requête qui l'a consommé. Deux
     * échanges simultanés du même code ne réussissent jamais tous les deux. `$linkId` est la liaison créée en l'échangeant (`null` quand
     * le code est brûlé par un échange refusé).
     */
    public function consume(string $codeHash, DateTimeImmutable $now, ?string $linkId): bool;

    /**
     * Efface les codes expirés du compte, usés ou non : un code expiré ne sert plus ni à échanger ni à reconnaître un rejeu.
     */
    public function deleteExpiredOfUser(string $userId, DateTimeImmutable $now): void;
}
