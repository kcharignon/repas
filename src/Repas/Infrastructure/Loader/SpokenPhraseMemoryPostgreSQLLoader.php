<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Application\Loader\SpokenPhraseMemory;
use Repas\Repas\Infrastructure\Entity\SpokenPhraseChoice;

final readonly class SpokenPhraseMemoryPostgreSQLLoader implements SpokenPhraseMemory
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function rememberedSlug(string $userId, string $phraseKey): ?string
    {
        /** @var list<array{slug: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('c.ingredientSlug AS slug')
            ->from(SpokenPhraseChoice::class, 'c')
            ->where('c.userId = :user')
            ->andWhere('c.phrase = :phrase')
            ->setParameter('user', $userId)
            ->setParameter('phrase', $phraseKey)
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();

        return $rows[0]['slug'] ?? null;
    }
}
