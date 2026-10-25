<?php

namespace Repas\Repas\Application\Loader;

/**
 * Port de lecture de la mémoire de phrase de la commande vocale (ADR 0029 décision 11, VOX-303) : ce que l'utilisateur a déjà choisi pour une
 * phrase que le classement seul ne tranchait pas.
 */
interface SpokenPhraseMemory
{
    /**
     * Le slug de l'ingrédient que l'utilisateur a choisi pour cette phrase, `null` s'il n'en a pas choisi. Une requête. Ce slug n'est pas
     * vérifié : l'ingrédient a pu disparaître depuis (le classeur ne retient que ce qui est parmi les candidats de l'utilisateur).
     *
     * @param string $phraseKey la clé de `SpokenIngredientRanker::phraseKey`
     */
    public function rememberedSlug(string $userId, string $phraseKey): ?string;
}
