<?php

namespace Repas\Repas\Infrastructure\Acl;

use Repas\Drive\Application\OpenHostService\IngredientChoiceCommandOHS;
use Repas\Repas\Application\Port\DriveChoicesCommandPort;
use Repas\Repas\Domain\Model\DriveChoiceFingerprint;

/**
 * Couche anticorruption (ADR 0035) : les choix de produits du drive, tels que le contexte `Drive` les publie pour qui écrit, deviennent
 * l'empreinte et les repointages que les écritures d'ingrédients attendent.
 */
final readonly class DriveChoicesCommandAdapter implements DriveChoicesCommandPort
{
    public function __construct(
        private IngredientChoiceCommandOHS $choices,
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

    public function repoint(string $userId, array $slugMap): int
    {
        return $this->choices->repoint($userId, $slugMap);
    }

    public function merge(string $userId, string $absorbedSlug, string $keptSlug): void
    {
        $this->choices->merge($userId, $absorbedSlug, $keptSlug);
    }
}
