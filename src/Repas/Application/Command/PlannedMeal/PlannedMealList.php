<?php

namespace Repas\Repas\Application\Command\PlannedMeal;

use DateTimeImmutable;

/**
 * Une liste qui a reçu un repas de la recette planifiée.
 */
final readonly class PlannedMealList
{
    public function __construct(
        public string $id,
        /** Le nom de la liste ; `null` pour une liste qui n'en a pas (elle se nomme alors par sa date) */
        public ?string $name,
        public DateTimeImmutable $createdAt,
        /** Le type de la liste, `null` pour la liste principale */
        public ?string $typeId,
    ) {
    }
}
