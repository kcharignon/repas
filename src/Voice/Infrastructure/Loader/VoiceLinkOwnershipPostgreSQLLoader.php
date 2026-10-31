<?php

namespace Repas\Voice\Infrastructure\Loader;


use Doctrine\ORM\EntityManagerInterface;
use Repas\Voice\Application\Loader\VoiceLinkOwnershipLoader;
use Repas\Voice\Domain\Exception\VoiceException;
use Repas\Voice\Infrastructure\Entity\VoiceLink;

final readonly class VoiceLinkOwnershipPostgreSQLLoader implements VoiceLinkOwnershipLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function voiceLinkOwnerId(string $linkId): string
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('l.userId AS owner')
            ->from(VoiceLink::class, 'l')
            ->where('l.id = :id')
            ->setParameter('id', $linkId)
            ->getQuery()
            ->getOneOrNullResult();

        return $row === null ? throw VoiceException::linkNotFound() : $row['owner'];
    }
}
