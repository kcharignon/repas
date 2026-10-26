<?php

namespace Repas\Repas\Application\Loader;

/**
 * Port de lecture de la mémoire de phrase des recettes de la commande vocale (ADR 0029 décision 13, VOX-305) : ce que l'utilisateur a déjà choisi
 * pour une phrase que le classement seul ne tranchait pas.
 */
interface SpokenRecipePhraseMemory
{
    /**
     * L'identifiant de la recette que l'utilisateur a choisie pour cette phrase, `null` s'il n'en a pas choisi. Une requête. Cet identifiant n'est
     * pas vérifié : la recette a pu disparaître depuis (le classeur ne retient que ce qui est parmi les candidats de l'utilisateur).
     *
     * @param string $phraseKey la clé de `SpokenIngredientRanker::phraseKey`
     */
    public function rememberedId(string $userId, string $phraseKey): ?string;
}
