<?php

namespace Repas\Repas\Domain\Interface;

use DateTimeImmutable;

/**
 * La mémoire de phrase de la commande vocale d'un utilisateur (ADR 0029 décision 11, VOX-303) : une ligne par (utilisateur, phrase
 * normalisée), qui désigne un de ses ingrédients. Aucune hydratation : des clés et des lignes. La lecture pour le choix d'un ingrédient dans une
 * écriture est `rememberedSlug` ; `SpokenPhraseMemory` (lecture seule) rend la même chose.
 */
interface SpokenPhraseChoiceRepository
{
    /**
     * Le slug de l'ingrédient que l'utilisateur a choisi pour cette phrase, `null` s'il n'en a pas choisi. Une requête. Ce slug n'est pas
     * vérifié : l'ingrédient a pu disparaître depuis (le classeur ne retient que ce qui est parmi les candidats de l'utilisateur).
     *
     * @param string $phraseKey la clé de `SpokenIngredientRanker::phraseKey`
     */
    public function rememberedSlug(string $userId, string $phraseKey): ?string;

    /**
     * Retient que `$phraseKey` désigne `$ingredientSlug` pour cet utilisateur. Le même choix de suite augmente `times` ; un autre choix
     * remplace l'ingrédient et repart à 1 (le dernier choix l'emporte). Une seule requête, simultanée ou non.
     */
    public function remember(string $userId, string $phraseKey, string $ingredientSlug, DateTimeImmutable $now): void;

    /**
     * Efface toute la mémoire d'un compte supprimé (ADR 0021). Opération en masse.
     */
    public function deleteByUser(string $userId): void;

    /**
     * Efface les phrases de cet utilisateur qui désignent cet ingrédient, quand il disparaît : un slug qui reviendrait plus tard (même nom
     * recréé) ne doit pas hériter de ce que l'utilisateur avait appris à Alexa.
     */
    public function deleteByIngredientSlug(string $userId, string $ingredientSlug): void;

    /**
     * Les phrases de cet utilisateur qui désignaient `$fromSlug` désignent `$toSlug` (fusion de deux ingrédients, ADR 0028 décision 9).
     */
    public function repoint(string $userId, string $fromSlug, string $toSlug): void;
}
