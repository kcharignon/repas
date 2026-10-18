<?php

namespace Repas\Shared\Application\Dto;


/**
 * Une liste de courses vue par le contexte `Drive` (docs/drive/architecture.md § 5.3). Jamais un modèle de domaine.
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
