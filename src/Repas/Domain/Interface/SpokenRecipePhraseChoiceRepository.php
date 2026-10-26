<?php

namespace Repas\Repas\Domain\Interface;

use DateTimeImmutable;

/**
 * La mémoire de phrase des RECETTES de la commande vocale d'un utilisateur (ADR 0029 décision 13, VOX-305) : une ligne par (utilisateur, phrase
 * normalisée), qui désigne une de ses recettes. Jumelle de `SpokenPhraseChoiceRepository` (les produits), séparée pour que les deux recherches ne se
 * mélangent jamais. Aucune hydratation : des clés et des lignes. La LECTURE est faite par `SpokenRecipePhraseMemory`.
 */
interface SpokenRecipePhraseChoiceRepository
{
    /**
     * Retient que `$phraseKey` désigne `$recipeId` pour cet utilisateur. Le même choix de suite augmente `times` ; un autre choix remplace la recette
     * et repart à 1 (le dernier choix l'emporte). Une seule requête, simultanée ou non.
     */
    public function remember(string $userId, string $phraseKey, string $recipeId, DateTimeImmutable $now): void;

    /**
     * Efface toute la mémoire d'un compte supprimé (ADR 0021). Opération en masse.
     */
    public function deleteByUser(string $userId): void;

    /**
     * Efface les phrases de cet utilisateur qui désignent cette recette, quand elle disparaît.
     */
    public function deleteByRecipeId(string $userId, string $recipeId): void;
}
