<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\IngredientDistinctPair;

/**
 * La mémoire des paires « Pas le même » d'un utilisateur (ADR 0028, ING-006) : une ligne par (utilisateur, paire non ordonnée). Aucune
 * hydratation d'ingrédient : des slugs et des lignes. La LECTURE pour l'écran est faite par `IngredientDuplicateLoader`.
 */
interface IngredientDistinctRepository
{
    /**
     * Mémorise la paire. Idempotent : la repose ne change rien (`ON CONFLICT DO NOTHING`, la première date est gardée). `true` si cet
     * appel l'a écrite, `false` si elle y était déjà.
     */
    public function save(IngredientDistinctPair $pair): bool;

    /**
     * Efface toutes les paires d'un compte supprimé (ADR 0021). Opération en masse.
     */
    public function deleteByUser(string $userId): void;

    /**
     * Efface les paires de cet utilisateur qui nomment cet ingrédient, quand il disparaît (suppression ou fusion) : un slug qui reviendrait
     * plus tard (même nom recréé) ne doit pas hériter d'un « Pas le même » qui parlait de l'ancien ingrédient.
     */
    public function deleteByIngredientSlug(string $userId, string $ingredientSlug): void;
}
