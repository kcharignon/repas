<?php

namespace Repas\Shared\Application\Dto;

/**
 * Ce que `SpokenListLookup::findMeal()` rend pour une phrase prononcée (ADR 0029 décision 12, VOX-306) : l'utilisateur n'a pas de liste active, SOIT
 * le repas n'y est pas (avec, quand il y en a, les repas planifiés qui lui ressemblent : au plus trois), SOIT un repas de la liste le porte. Un seul cas à
 * la fois. Comme pour les produits, la liste envoyée au drive (jamais lue ni modifiée) se distingue de l'absence de liste : `listSent`.
 */
final readonly class SpokenMealLookupResult
{
    /**
     * @param list<ListedMeal> $suggestions les repas planifiés les plus proches de la phrase (seulement quand le repas n'y est pas)
     */
    private function __construct(
        public bool $hasList,
        public ?ListedMeal $meal,
        public array $suggestions,
        public bool $listSent = false,
    ) {
    }

    /** Aucune liste active, ni envoyée au drive. */
    public static function noList(): self
    {
        return new self(false, null, []);
    }

    /** Aucune liste active, mais une liste envoyée au drive : elle n'est ni lue ni modifiée. */
    public static function sentList(): self
    {
        return new self(false, null, [], true);
    }

    /** Un repas de la liste active est celui que la phrase désigne. */
    public static function found(ListedMeal $meal): self
    {
        return new self(true, $meal, []);
    }

    /**
     * Une liste active, mais aucun de ses repas ne se laisse choisir sûrement.
     *
     * @param list<ListedMeal> $suggestions les repas planifiés les plus proches (au plus trois, aucun : rien d'assez proche)
     */
    public static function notPlanned(array $suggestions = []): self
    {
        return new self(true, null, $suggestions);
    }
}
