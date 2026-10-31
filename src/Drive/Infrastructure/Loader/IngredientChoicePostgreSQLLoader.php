<?php

namespace Repas\Drive\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Repas\Drive\Application\Loader\IngredientChoiceLoader;
use Repas\Drive\Infrastructure\Reader\IngredientChoiceReader;

/**
 * Lecture seule des choix de produits par ingrédient (rapport de l'amorçage, aperçu de la fusion). Les lectures sont celles de
 * `IngredientChoiceReader`, le même code que le Repository de l'écriture : un rapport dit ce que l'écriture verra.
 */
final readonly class IngredientChoicePostgreSQLLoader implements IngredientChoiceLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private IngredientChoiceReader $reader,
    ) {
    }

    public function fingerprint(string $userId): array
    {
        return $this->reader->fingerprint($userId);
    }

    public function referencedSlugs(string $userId): array
    {
        return $this->reader->referencedSlugs($userId);
    }

    public function referencedSlugsOfEveryUser(): array
    {
        $slugs = [];
        foreach ($this->entityManager->getConnection()->fetchAllNumeric('SELECT DISTINCT user_id, ingredient_slug FROM drive_choice ORDER BY user_id, ingredient_slug') as [$userId, $slug]) {
            $slugs[$userId][] = $slug;
        }

        return $slugs;
    }

    public function mergeImpact(string $userId, string $absorbedSlug, string $keptSlug): array
    {
        return $this->reader->mergeImpact($userId, $absorbedSlug, $keptSlug);
    }
}
