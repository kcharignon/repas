<?php

namespace Repas\Voice\Infrastructure\Loader;


use Doctrine\ORM\EntityManagerInterface;
use Repas\Voice\Application\Loader\VoiceLinkLoader;
use Repas\Voice\Application\Query\ListVoiceLinks\VoiceLinkDTO;
use Repas\Voice\Domain\Model\VoiceLink;
use Repas\Voice\Infrastructure\Entity\VoiceLink as VoiceLinkEntity;

final readonly class VoiceLinkPostgreSQLLoader implements VoiceLinkLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function listOfUser(string $userId): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('l.id AS id', 'l.createdAt AS createdAt', 'l.lastUsedAt AS lastUsedAt')
            ->from(VoiceLinkEntity::class, 'l')
            ->where('l.userId = :userId')
            ->andWhere('l.revokedAt IS NULL')
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'ASC')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn(array $row): VoiceLinkDTO => new VoiceLinkDTO(
                id: $row['id'],
                provider: VoiceLink::PROVIDER,
                createdAt: $row['createdAt']->format(DATE_ATOM),
                lastUsedAt: $row['lastUsedAt']?->format(DATE_ATOM),
            ),
            $rows,
        );
    }
}
