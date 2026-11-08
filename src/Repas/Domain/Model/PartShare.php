<?php

namespace Repas\Repas\Domain\Model;


/**
 * Ce qu'une liste de courses a gardé d'une PARTIE d'ingrédient (« Jaune d'œuf », ADR 0038 décisions 6 à 8) : une partie n'est jamais une ligne
 * de la liste, elle se plie dans la ligne de son parent ; le registre de la liste (`shopping_list.part_shares`) garde, par partie, de quoi
 * retrouver le plus grand des équivalents du parent (« 2 jaunes et 2 blancs, c'est 2 œufs, pas 4 »).
 *
 *  - `parentSlug`  : le parent dont la ligne porte le total ; celui de l'instant de l'ajout (changer le parent ensuite ne déplace rien) ;
 *  - `quantity`    : ce que la liste a reçu de la partie, dans l'UNITÉ D'ACHAT DE LA PARTIE (ce que « Comprend 2 jaunes d'œuf » dit) ;
 *  - `equivalent`  : ce que cette quantité vaut, dans l'unité d'achat DU PARENT (`PartEquivalence`), calculé une fois à l'ajout.
 *
 * Un retrait est proportionnel à ces deux nombres (retirer 1 sur 4 retire un quart de l'équivalent) : ni ratio ni conversion à retrouver, donc
 * rien ne dérive si l'utilisateur change le ratio ensuite.
 *
 * Le registre est du JSON gardé en base : il se lit comme une entrée NON FIABLE (`registryFrom`, comme `meal.placed_ingredients`) : tolérant, borné,
 * jamais d'exception ; une entrée qui n'a pas de sens (quantité nulle, négative, non finie) est ignorée plutôt que de casser la liste.
 */
final readonly class PartShare
{
    /** Entrées lues au plus : un parent a au plus `Ingredient::MAX_PARTS` parties, une liste n'a jamais mille parents. */
    private const int MAX_ENTRIES = 1000;

    public function __construct(
        public string $parentSlug,
        public float $quantity,
        public float $equivalent,
    ) {
    }

    /**
     * La forme gardée en base : `{parent, quantity, equivalent}`.
     *
     * @return array{parent: string, quantity: float, equivalent: float}
     */
    public function toArray(): array
    {
        return ['parent' => $this->parentSlug, 'quantity' => $this->quantity, 'equivalent' => $this->equivalent];
    }

    /**
     * Le registre tel qu'on le garde : `{ "<slug de la partie>": {parent, quantity, equivalent} }`, `null` quand il est vide (une liste sans
     * partie n'écrit rien).
     *
     * @param array<string, PartShare> $shares
     *
     * @return ?array<string, array{parent: string, quantity: float, equivalent: float}>
     */
    public static function registryToArray(array $shares): ?array
    {
        if ($shares === []) {
            return null;
        }

        return array_map(static fn(PartShare $share): array => $share->toArray(), $shares);
    }

    /**
     * Lit un registre venu de la base (ou de n'importe où) : une entrée mal formée est ignorée, jamais une exception. Les nombres entiers du
     * JSON valent leur flottant ; une quantité ou un équivalent qui n'est pas un nombre fini strictement positif ne fait pas une entrée.
     *
     * @return array<string, PartShare> par slug de partie
     */
    public static function registryFrom(mixed $stored): array
    {
        if (!is_array($stored)) {
            return [];
        }

        $shares = [];
        foreach ($stored as $partSlug => $entry) {
            if (count($shares) >= self::MAX_ENTRIES) {
                break;
            }
            $share = self::entryFrom($entry);
            if ($share !== null && is_string($partSlug) && $partSlug !== '') {
                $shares[$partSlug] = $share;
            }
        }

        return $shares;
    }

    private static function entryFrom(mixed $entry): ?self
    {
        if (!is_array($entry) || !is_string($entry['parent'] ?? null) || $entry['parent'] === '') {
            return null;
        }
        $quantity = $entry['quantity'] ?? null;
        $equivalent = $entry['equivalent'] ?? null;
        if (!(is_int($quantity) || is_float($quantity)) || !(is_int($equivalent) || is_float($equivalent))) {
            return null;
        }
        if (!is_finite((float) $quantity) || !is_finite((float) $equivalent) || $quantity <= 0 || $equivalent <= 0) {
            return null;
        }

        return new self($entry['parent'], (float) $quantity, (float) $equivalent);
    }
}
