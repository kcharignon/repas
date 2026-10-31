<?php

namespace Repas\Shared\Application\Dto;

/**
 * Ce que `SpokenListLookup::find()` rend pour une phrase prononcée (ADR 0029 décision 12, VOX-304) : l'utilisateur n'a pas de liste active,
 * SOIT le produit n'y est pas (avec le nom de l'ingrédient reconnu, s'il l'est), SOIT une ligne le porte, SOIT plusieurs lignes se valent
 * (« tomate » en fruit et légume ET en surgelé, toutes deux dans la liste) : Alexa demande lequel pour retirer, ou répond pour toutes pour
 * consulter. Un seul cas à la fois. Sans liste active, la liste envoyée au drive (jamais lue ni modifiée) se distingue de l'absence de liste :
 * `listSent`, de quoi dire une phrase vraie (« Ta liste est déjà partie au drive. »).
 *
 * Quand le produit n'y est pas ET que la phrase ne désigne aucun ingrédient de l'utilisateur (`absentName` vide), `suggestions` porte les lignes de la
 * liste qui lui ressemblent (au plus trois, ADR 0029 décision 12 amendée par VOX-306) : Alexa propose de retirer l'une d'elles.
 */
final readonly class SpokenListLookupResult
{
    /**
     * @param list<ListedLine> $lines       les lignes de la liste qui portent le produit (aucune : il n'y est pas)
     * @param bool             $listSent    aucune liste active, mais l'utilisateur en a une envoyée au drive (jamais vrai avec `hasList`)
     * @param list<ListedLine> $suggestions les lignes de la liste les plus proches d'une phrase qui ne désigne aucun ingrédient (au plus trois)
     */
    private function __construct(
        public bool $hasList,
        public array $lines,
        public ?string $absentName,
        public bool $listSent = false,
        public array $suggestions = [],
    ) {
    }

    /** Aucune liste active, ni envoyée au drive. */
    public static function noList(): self
    {
        return new self(false, [], null);
    }

    /** Aucune liste active, mais une liste envoyée au drive : elle n'est ni lue ni modifiée. */
    public static function sentList(): self
    {
        return new self(false, [], null, true);
    }

    /**
     * Une liste active, mais le produit n'y est pas.
     *
     * @param ?string $name le nom de l'ingrédient reconnu (« croquette »), `null` si la phrase ne désigne aucun ingrédient de l'utilisateur
     * @param list<ListedLine> $suggestions les lignes de la liste les plus proches de la phrase (seulement si `$name` est vide)
     */
    public static function notInList(?string $name, array $suggestions = []): self
    {
        return new self(true, [], $name, false, $suggestions);
    }

    public static function found(ListedLine $line): self
    {
        return new self(true, [$line], null);
    }

    /**
     * @param list<ListedLine> $lines au moins deux lignes, toutes dans la liste, que le nom ou le rayon distingue
     */
    public static function tied(array $lines): self
    {
        return new self(true, $lines, null);
    }

    public function isTied(): bool
    {
        return count($this->lines) > 1;
    }
}
