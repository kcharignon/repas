<?php

namespace Repas\Repas\Infrastructure\Acl;

use Repas\Drive\Application\OpenHostService\IngredientChoiceQueryOHS;
use Repas\Repas\Application\Port\DriveChoicesQueryPort;
use Repas\Repas\Domain\Model\DriveChoiceFingerprint;
use Repas\Repas\Domain\Model\DriveMergeImpact;

/**
 * Couche anticorruption (ADR 0035) : les choix de produits du drive, tels que le contexte `Drive` les publie pour qui lit, deviennent
 * l'empreinte, les slugs et l'aperçu de fusion que les lectures d'ingrédients attendent.
 */
final readonly class DriveChoicesQueryAdapter implements DriveChoicesQueryPort
{
    public function __construct(
        private IngredientChoiceQueryOHS $choices,
    ) {
    }

    public function fingerprint(string $userId): DriveChoiceFingerprint
    {
        $fingerprint = $this->choices->fingerprint($userId);

        return new DriveChoiceFingerprint(
            $fingerprint->choices,
            $fingerprint->products,
            $fingerprint->packSizeSum,
            $fingerprint->contentHash,
            $fingerprint->ingredientSlugByChoice,
            $fingerprint->productCountByChoice,
        );
    }

    public function referencedSlugs(string $userId): array
    {
        return $this->choices->referencedSlugs($userId);
    }

    public function referencedSlugsOfEveryUser(): array
    {
        return $this->choices->referencedSlugsOfEveryUser();
    }

    public function mergeImpact(string $userId, string $absorbedSlug, string $keptSlug): DriveMergeImpact
    {
        $impact = $this->choices->mergeImpact($userId, $absorbedSlug, $keptSlug);

        return new DriveMergeImpact($impact->choicesMoved, $impact->choicesDropped, $impact->productsDropped);
    }
}
