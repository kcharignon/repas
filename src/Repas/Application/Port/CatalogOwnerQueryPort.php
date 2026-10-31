<?php

namespace Repas\Repas\Application\Port;

/**
 * Ce que les lectures d'ingrédients demandent aux comptes : qui est le propriétaire du catalogue (ADR 0028, amendement du 2026-10-20), seul
 * à voir le marqueur « de base » et cible du rapport de l'amorçage. Adapté depuis le contexte `User` (ADR 0035).
 */
interface CatalogOwnerQueryPort
{
    /**
     * L'identifiant du compte propriétaire du catalogue, ou `null` s'il n'existe pas. Chaque appel relit : l'appelant ne le demande que si la
     * réponse change quelque chose, et une fois par lecture.
     */
    public function ownerId(): ?string;
}
