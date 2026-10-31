<?php

namespace Repas\Drive\Domain\Model;


/**
 * Une liste de courses vue par le contexte `Drive` (docs/drive/architecture.md § 5.3), transposée du contenu que `Repas` publie par la couche anticorruption (ADR 0035).
 */
final readonly class ShoppingListForDrive
{
    /**
     * @param list<ShoppingListLineForDrive> $lines triées par slug d'ingrédient
     */
    public function __construct(
        public string $id,
        public string $ownerId,
        /** Valeur du statut de la liste : `ACTIVE`, `PAUSED`, `SENT` ou `COMPLETED`. */
        public string $status,
        public int $version,
        public array $lines,
    ) {
    }
}
