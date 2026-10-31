<?php

namespace Repas\Voice\Infrastructure\Acl;

use Repas\Repas\Application\OpenHostService\IngredientCreationCommandOHS;
use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Domain\Model\Unit;
use Repas\Voice\Application\Port\IngredientCreationCommandPort;
use Repas\Voice\Domain\Model\CreatedIngredient;
use Repas\Voice\Domain\Model\DialogueOption;

/**
 * Couche anticorruption (ADR 0035) : les rayons et les unités de `Repas` deviennent des choix du dialogue (slug et nom), l'ingrédient créé
 * devient celui que la réponse vocale cite. Les exceptions de `Repas` (rayon ou unité inconnus, ingrédient déjà possédé) remontent telles
 * quelles : le bus transactionnel annule la commande entière.
 */
final readonly class IngredientCreationCommandAdapter implements IngredientCreationCommandPort
{
    public function __construct(
        private IngredientCreationCommandOHS $ingredients,
    ) {
    }

    public function departments(): array
    {
        return array_map(
            static fn(Department $department): DialogueOption => new DialogueOption($department->getSlug(), $department->getName()),
            $this->ingredients->departments(),
        );
    }

    public function units(): array
    {
        return array_map(
            static fn(Unit $unit): DialogueOption => new DialogueOption($unit->getSlug(), $unit->getName()),
            $this->ingredients->units(),
        );
    }

    public function create(string $userId, string $name, string $departmentSlug, string $unitSlug): CreatedIngredient
    {
        $created = $this->ingredients->create($userId, $name, $departmentSlug, $unitSlug);

        return new CreatedIngredient($created->slug, $created->name, $created->departmentName);
    }
}
