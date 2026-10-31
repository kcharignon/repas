<?php

namespace Repas\Repas\Domain\Model;

/**
 * Ce qu'a fait le retrait d'un repas des listes en cours (ADR 0029 décision 12, VOX-306) : le repas a quitté la liste, ou son nombre de personnes a
 * baissé, ou il n'était pas dans une liste en cours (rien n'est écrit), ou une des listes de sa planification est partie au drive (rien n'est écrit, ADR
 * 0036 décision 9).
 */
final readonly class MealRemovalResult
{
    private function __construct(
        public bool $found,
        public string $recipeName,
        /** Le repas a quitté la liste (tout retiré, ou autant de personnes que le repas en comptait). */
        public bool $removedAll,
        /** Le nombre de personnes qui reste quand le repas n'a pas quitté la liste (0 sinon). */
        public int $servings,
        /** Une liste de la planification du repas est partie au drive : tout est refusé, rien n'est écrit. */
        public bool $listSent = false,
    ) {
    }

    public static function removed(string $recipeName): self
    {
        return new self(true, $recipeName, true, 0);
    }

    public static function lowered(string $recipeName, int $servings): self
    {
        return new self(true, $recipeName, false, $servings);
    }

    /** Pas de liste en cours, ou la recette n'y est plus : rien n'a été écrit. */
    public static function notPlanned(string $recipeName = ''): self
    {
        return new self(false, $recipeName, false, 0);
    }

    /** Le repas est dans une planification dont une liste est partie au drive : elle n'est jamais modifiée, donc rien ne l'est. */
    public static function listSent(string $recipeName): self
    {
        return new self(false, $recipeName, false, 0, listSent: true);
    }
}
