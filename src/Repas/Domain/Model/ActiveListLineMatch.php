<?php

namespace Repas\Repas\Domain\Model;

/**
 * Ce que `ActiveListLookup::line()` rend pour une phrase (ADR 0029 décision 12, VOX-304) : l'utilisateur n'a pas de liste active,
 * SOIT le produit n'y est pas (avec le nom de l'ingrédient reconnu, s'il l'est), SOIT une ligne le porte, SOIT plusieurs lignes se valent
 * (« tomate » en fruit et légume ET en surgelé, toutes deux dans la liste) : l'appelant demande lequel pour retirer, ou répond pour toutes pour
 * consulter. Un seul cas à la fois. Sans liste active, la liste envoyée au drive (jamais lue ni modifiée) se distingue de l'absence de liste :
 * `listSent`.
 *
 * Quand le produit n'y est pas ET que la phrase ne désigne aucun ingrédient de l'utilisateur (`absentName` vide), `suggestions` porte les lignes de la
 * liste qui lui ressemblent (au plus trois, ADR 0029 décision 12 amendée par VOX-306) : l'appelant propose d'en retirer une.
 */
final readonly class ActiveListLineMatch
{
    /**
     * @param list<ActiveListLine> $lines       les lignes qui portent le produit, une par liste en cours qui le porte (aucune : il n'y est pas)
     * @param bool             $listSent    aucune liste active, mais l'utilisateur en a une envoyée au drive (jamais vrai avec `hasList`)
     * @param list<ActiveListLine> $suggestions les lignes de la liste les plus proches d'une phrase qui ne désigne aucun ingrédient (au plus trois)
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
     * @param list<ActiveListLine> $suggestions les lignes de la liste les plus proches de la phrase (seulement si `$name` est vide)
     */
    public static function notInList(?string $name, array $suggestions = []): self
    {
        return new self(true, [], $name, false, $suggestions);
    }

    public static function found(ActiveListLine $line): self
    {
        return new self(true, [$line], null);
    }

    /**
     * Un produit porté par plusieurs listes en cours (ADR 0036, LST-008) : une ligne par liste, la liste principale d'abord.
     *
     * @param non-empty-list<ActiveListLine> $lines les lignes d'UN SEUL produit
     */
    public static function foundIn(array $lines): self
    {
        return new self(true, $lines, null);
    }

    /**
     * @param list<ActiveListLine> $lines les lignes d'au moins deux produits, tous dans une liste en cours, que le nom ou le rayon distingue (un produit
     *                                    porté par deux listes y a deux lignes)
     */
    public static function tied(array $lines): self
    {
        return new self(true, $lines, null);
    }

    /** Plusieurs PRODUITS se valent (le nom ou le rayon les distingue) : un produit dans plusieurs listes n'est pas un départage. */
    public function isTied(): bool
    {
        $products = [];
        foreach ($this->lines as $line) {
            $products[$line->name . '|' . $line->departmentSlug] = true;
        }

        return count($products) > 1;
    }
}
