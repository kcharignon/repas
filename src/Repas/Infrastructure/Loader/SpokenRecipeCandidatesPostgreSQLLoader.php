<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Application\Loader\SpokenRecipeCandidates;
use Repas\Repas\Domain\Model\SpokenCandidate;
use Repas\Repas\Infrastructure\Entity\Recipe;
use Repas\Shared\Domain\Tool\StringTool;

/**
 * Les recettes de l'utilisateur, en une requête sur `recipe` (le nom et l'identifiant, rien d'hydraté). Le nom normalisé est recalculé du nom : la
 * colonne `slug` n'est pas tenue à jour par un renommage. Une recette n'a ni rayon ni habitude (`uses12m` à 0).
 */
final readonly class SpokenRecipeCandidatesPostgreSQLLoader implements SpokenRecipeCandidates
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function forUser(string $userId): array
    {
        /** @var list<array{id: string, name: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.id AS id', 'r.name AS name')
            ->from(Recipe::class, 'r')
            ->where('r.authorId = :author')
            ->setParameter('author', $userId)
            ->orderBy('r.id')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn(array $row): SpokenCandidate => new SpokenCandidate($row['id'], StringTool::slugify($row['name']), $row['name'], ''),
            $rows,
        );
    }
}
