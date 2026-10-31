<?php

namespace Repas\Repas\Application\OpenHostService;

/**
 * Une liste de courses publiée par `Repas` pour un autre contexte (ses lignes, son propriétaire, son statut et sa version). Jamais un
 * modèle de domaine.
 */
final readonly class ShoppingListContentDTO
{
    /**
     * @param list<ShoppingListContentLineDTO> $lines triées par slug d'ingrédient
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
