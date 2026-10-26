<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Application\Loader\SpokenRecipePhraseMemory;
use Repas\Repas\Infrastructure\Entity\SpokenRecipePhraseChoice;

final readonly class SpokenRecipePhraseMemoryPostgreSQLLoader implements SpokenRecipePhraseMemory
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function rememberedId(string $userId, string $phraseKey): ?string
    {
        /** @var list<array{id: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('c.recipeId AS id')
            ->from(SpokenRecipePhraseChoice::class, 'c')
            ->where('c.userId = :user')
            ->andWhere('c.phrase = :phrase')
            ->setParameter('user', $userId)
            ->setParameter('phrase', $phraseKey)
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();

        return $rows[0]['id'] ?? null;
    }
}
