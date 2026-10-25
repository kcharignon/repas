<?php

namespace Repas\Voice\Infrastructure\Repository;


use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;
use Repas\Voice\Domain\Interface\VoiceLinkRepository;
use Repas\Voice\Domain\Model\VoiceLink;
use Repas\Voice\Infrastructure\Entity\VoiceLink as VoiceLinkEntity;

readonly class VoiceLinkPostgreSQLRepository extends PostgreSQLRepository implements VoiceLinkRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, VoiceLinkEntity::class);
    }

    public function findOneById(string $id): ?VoiceLink
    {
        $entity = $this->entityRepository->find($id);

        return $entity instanceof VoiceLinkEntity ? $this->convertEntityToModel($entity) : null;
    }

    public function findAwaitingAlexaOfUser(string $userId): ?VoiceLink
    {
        $entity = $this->entityManager->createQueryBuilder()
            ->select('l')
            ->from(VoiceLinkEntity::class, 'l')
            ->where('l.userId = :userId')
            ->andWhere('l.alexaUserId IS NULL')
            ->andWhere('l.revokedAt IS NULL')
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'ASC')
            ->setMaxResults(1)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        return $entity instanceof VoiceLinkEntity ? $this->convertEntityToModel($entity) : null;
    }

    public function save(VoiceLink $link): void
    {
        $entity = $this->entityRepository->find($link->getId());
        if (!$entity instanceof VoiceLinkEntity) {
            $entity = new VoiceLinkEntity();
            $entity->id = $link->getId();
            $this->entityManager->persist($entity);
        }

        $entity->userId = $link->getUserId();
        $entity->alexaUserId = $link->getAlexaUserId();
        $entity->createdAt = $link->getCreatedAt();
        $entity->lastUsedAt = $link->getLastUsedAt();
        $entity->revokedAt = $link->getRevokedAt();

        $this->entityManager->flush();
    }

    /**
     * Une seule instruction, qui ne touche que `last_used_at` : ni `save()` du modèle, ni l'entité (l'identity map de Doctrine reste telle
     * quelle, sans conséquence : aucun `flush` ne réécrit une entité qui n'a pas changé).
     */
    public function recordUse(string $linkId, DateTimeImmutable $at): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE voice_link SET last_used_at = :at WHERE id = :id AND revoked_at IS NULL AND (last_used_at IS NULL OR last_used_at < :at)',
            // Doctrine relit les dates dans le fuseau par défaut de PHP : on écrit dans le même, quel que soit celui de `$at`
            ['at' => $at->setTimezone(new DateTimeZone(date_default_timezone_get())), 'id' => $linkId],
            ['at' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function identifyAlexaUser(string $linkId, string $alexaUserId): bool
    {
        return $this->entityManager->getConnection()->executeStatement(
            'UPDATE voice_link SET alexa_user_id = :alexaUserId WHERE id = :id AND alexa_user_id IS NULL AND revoked_at IS NULL',
            ['alexaUserId' => $alexaUserId, 'id' => $linkId],
        ) === 1;
    }

    public function findOthersOfAlexaUser(string $userId, string $alexaUserId, string $exceptLinkId): array
    {
        $entities = $this->entityManager->createQueryBuilder()
            ->select('l')
            ->from(VoiceLinkEntity::class, 'l')
            ->where('l.userId = :userId')
            ->andWhere('l.alexaUserId = :alexaUserId')
            ->andWhere('l.id <> :exceptLinkId')
            ->andWhere('l.revokedAt IS NULL')
            ->orderBy('l.createdAt', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->setParameter('userId', $userId)
            ->setParameter('alexaUserId', $alexaUserId)
            ->setParameter('exceptLinkId', $exceptLinkId)
            ->getQuery()
            ->getResult();

        return array_map(fn(VoiceLinkEntity $entity): VoiceLink => $this->convertEntityToModel($entity), $entities);
    }

    private function convertEntityToModel(VoiceLinkEntity $entity): VoiceLink
    {
        return VoiceLink::load([
            'id' => $entity->id,
            'user_id' => $entity->userId,
            'alexa_user_id' => $entity->alexaUserId,
            'created_at' => $entity->createdAt,
            'last_used_at' => $entity->lastUsedAt,
            'revoked_at' => $entity->revokedAt,
        ]);
    }
}
