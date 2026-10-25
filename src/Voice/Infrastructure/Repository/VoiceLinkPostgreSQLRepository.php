<?php

namespace Repas\Voice\Infrastructure\Repository;


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
