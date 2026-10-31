<?php

namespace Repas\Drive\Infrastructure\Acl;

use Repas\Drive\Application\Port\IngredientNameQueryPort;
use Repas\Repas\Application\OpenHostService\IngredientNameQueryOHS;

/**
 * Couche anticorruption (ADR 0035) : le nom des ingrédients que le contexte `Repas` publie pour qui lit, tel quel (des textes de part et
 * d'autre).
 */
final readonly class IngredientNameQueryAdapter implements IngredientNameQueryPort
{
    public function __construct(
        private IngredientNameQueryOHS $names,
    ) {
    }

    public function namesBySlug(string $userId, array $slugs): array
    {
        return $this->names->namesBySlug($userId, $slugs);
    }
}
