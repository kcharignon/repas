<?php

namespace Repas\Repas\Infrastructure\Reader;

use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Infrastructure\Entity\SpokenRecipePhraseChoice;

/**
 * La lecture de la mémoire de phrase des recettes, écrite une seule fois : `SpokenRecipePhraseMemoryPostgreSQLLoader` (lecture) comme
 * `SpokenRecipePhraseChoicePostgreSQLRepository` (écriture en cours, ADR 0035) rendent donc exactement la même chose.
 */
final readonly class SpokenRecipePhraseReader
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
