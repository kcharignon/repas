<?php

namespace Repas\Drive\Infrastructure\Acl;

use Repas\Drive\Application\Port\IngredientDepartmentCommandPort;
use Repas\Repas\Application\OpenHostService\IngredientDepartmentCommandOHS;

/**
 * Couche anticorruption (ADR 0035) : le rayon des ingrédients que le contexte `Repas` publie pour qui écrit, tel quel (des identifiants de
 * part et d'autre).
 */
final readonly class IngredientDepartmentCommandAdapter implements IngredientDepartmentCommandPort
{
    public function __construct(
        private IngredientDepartmentCommandOHS $departments,
    ) {
    }

    public function departmentSlugsByIngredient(array $ingredientSlugs): array
    {
        return $this->departments->departmentSlugsByIngredient($ingredientSlugs);
    }
}
