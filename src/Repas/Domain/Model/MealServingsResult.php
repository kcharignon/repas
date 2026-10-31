<?php

namespace Repas\Repas\Domain\Model;

/**
 * Ce qu'a fait le changement du nombre de personnes d'un repas des listes en cours (ADR 0029 décision 13, VOX-305) : le repas a un nouveau nombre de
 * personnes, ou il n'est plus dans une liste en cours (rien n'est écrit), ou une des listes de sa planification est partie au drive (rien n'est écrit,
 * ADR 0036 décision 9).
 */
final readonly class MealServingsResult
{
    private function __construct(
        public bool $found,
        public string $recipeName,
        public int $servings,
        /** Une liste de la planification du repas est partie au drive : tout est refusé, rien n'est écrit. */
        public bool $listSent = false,
    ) {
    }

    public static function changed(string $recipeName, int $servings): self
    {
        return new self(true, $recipeName, $servings);
    }

    /** Pas de liste en cours, ou la recette n'y est plus : rien n'a été écrit. */
    public static function notPlanned(string $recipeName = ''): self
    {
        return new self(false, $recipeName, 0);
    }

    /** Le repas est dans une planification dont une liste est partie au drive : elle n'est jamais modifiée, donc rien ne l'est. */
    public static function listSent(string $recipeName): self
    {
        return new self(false, $recipeName, 0, listSent: true);
    }
}
