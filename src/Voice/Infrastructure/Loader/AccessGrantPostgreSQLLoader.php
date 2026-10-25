<?php

namespace Repas\Voice\Infrastructure\Loader;


use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Repas\Voice\Application\Loader\AccessGrantLoader;
use Repas\Voice\Application\Query\ResolveAccessGrant\ResolvedAccessGrantDTO;
use Repas\Voice\Infrastructure\Entity\AccessGrant;
use Repas\Voice\Infrastructure\Entity\VoiceLink;

/**
 * Une seule requête pour résoudre un jeton d'accès (une par requête d'Alexa) : la paire par son haché, non expirée, et sa liaison, non
 * révoquée et du même compte (colonne `user_id` de la paire et de la liaison : si elles divergeaient, rien ne se résout).
 */
final readonly class AccessGrantPostgreSQLLoader implements AccessGrantLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function resolve(string $accessHash, DateTimeImmutable $now): ?ResolvedAccessGrantDTO
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('g.userId AS userId', 'g.linkId AS linkId')
            ->from(AccessGrant::class, 'g')
            ->innerJoin(VoiceLink::class, 'l', Join::WITH, 'l.id = g.linkId')
            ->where('g.accessHash = :accessHash')
            ->andWhere('g.expiresAt > :now')
            ->andWhere('l.revokedAt IS NULL')
            ->andWhere('l.userId = g.userId')
            ->setMaxResults(1)
            ->setParameter('accessHash', $accessHash)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getOneOrNullResult();

        return $row === null ? null : new ResolvedAccessGrantDTO($row['userId'], $row['linkId']);
    }
}
