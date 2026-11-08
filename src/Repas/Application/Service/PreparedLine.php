<?php

namespace Repas\Repas\Application\Service;


use Repas\Repas\Domain\Model\ShoppingList;

/**
 * Une quantité à poser dans une liste de courses, jugée AVANT la première écriture (une conversion manquante refuse tout) : soit la ligne d'un
 * ingrédient ordinaire, soit la part d'une PARTIE d'ingrédient (ADR 0038), qui n'est jamais une ligne et se plie dans celle de son parent.
 * `PartAwareLines::prepare` la construit, l'appelant la pose liste par liste avec `addTo`.
 */
final readonly class PreparedLine
{
    /**
     * @param string  $ingredientSlug       l'ingrédient de la ligne de recette (la partie, pour une partie)
     * @param string  $purchaseUnitSlug     son unité d'achat : celle de la ligne, ou de la quantité de la partie
     * @param float   $quantity             dans l'unité d'achat de `$ingredientSlug`
     * @param ?string $parentSlug           le parent dont la ligne porte le total, `null` pour un ingrédient ordinaire
     * @param ?string $parentPurchaseUnitSlug l'unité d'achat du parent (celle de sa ligne)
     * @param ?float  $equivalent           ce que `$quantity` vaut dans l'unité d'achat du parent (`PartEquivalence`)
     */
    public function __construct(
        private string $ingredientSlug,
        private string $purchaseUnitSlug,
        private float $quantity,
        private ?string $parentSlug = null,
        private ?string $parentPurchaseUnitSlug = null,
        private ?float $equivalent = null,
    ) {
    }

    public function isPart(): bool
    {
        return $this->parentSlug !== null;
    }

    /**
     * Pose la quantité dans la liste : `addRow` pour un ingrédient ordinaire (exactement comme avant les parties), `addPart` pour une partie.
     */
    public function addTo(ShoppingList $list): void
    {
        if ($this->parentSlug === null || $this->parentPurchaseUnitSlug === null || $this->equivalent === null) {
            $list->addRow($this->ingredientSlug, $this->purchaseUnitSlug, $this->quantity);

            return;
        }

        $list->addPart($this->ingredientSlug, $this->parentSlug, $this->parentPurchaseUnitSlug, $this->quantity, $this->equivalent);
    }
}
