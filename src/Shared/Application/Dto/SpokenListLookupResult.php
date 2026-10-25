<?php

namespace Repas\Shared\Application\Dto;

/**
 * Ce que `SpokenListLookup::find()` rend pour une phrase prononcée (ADR 0029 décision 12, VOX-304) : l'utilisateur n'a pas de liste active,
 * SOIT le produit n'y est pas (avec le nom de l'ingrédient reconnu, s'il l'est), SOIT une ligne le porte, SOIT plusieurs lignes se valent
 * (« tomate » en fruit et légume ET en surgelé, toutes deux dans la liste) : Alexa demande lequel pour retirer, ou répond pour toutes pour
 * consulter. Un seul cas à la fois.
 */
final readonly class SpokenListLookupResult
{
    /**
     * @param list<ListedLine> $lines les lignes de la liste qui portent le produit (aucune : il n'y est pas)
     */
    private function __construct(
        public bool $hasList,
        public array $lines,
        public ?string $absentName,
    ) {
    }

    /** Aucune liste active (aucune, ou envoyée au drive : elle n'est jamais modifiée). */
    public static function noList(): self
    {
        return new self(false, [], null);
    }

    /**
     * Une liste active, mais le produit n'y est pas.
     *
     * @param ?string $name le nom de l'ingrédient reconnu (« croquette »), `null` si la phrase ne désigne aucun ingrédient de l'utilisateur
     */
    public static function notInList(?string $name): self
    {
        return new self(true, [], $name);
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
