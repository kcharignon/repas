<?php

namespace Repas\Voice\Application\Port;

use Repas\Voice\Domain\Model\AddedItem;
use Repas\Voice\Domain\Model\RemovedItem;

/**
 * Ce que les écritures vocales demandent à la liste de courses de l'utilisateur : y ajouter un article (ADR 0029 décision 5, VOX-103) ou en
 * retirer (décision 12, VOX-304). Adapté depuis le contexte `Repas` (ADR 0035).
 *
 * La liste active reçoit la ligne (une unité d'achat par défaut, ou la quantité et l'unité dites), ou s'ajoute à la ligne de cet ingrédient qui
 * y est déjà ; sans liste active (aucune, ou envoyée au drive), une nouvelle liste est créée. Une liste envoyée n'est jamais modifiée.
 */
interface ShoppingListItemCommandPort
{
    /**
     * @param string  $ingredientSlug un ingrédient de l'utilisateur, ou un ancien slug que ses alias traduisent
     * @param int     $quantity       combien, de 1 à 99
     * @param ?string $unitSlug       l'unité dite : utilisée seulement si l'ingrédient la connaît (son unité d'achat ou une de ses unités
     *                                compatibles) ; sinon la ligne garde son unité (`AddedItem::unitSubstituted`)
     *
     * @throws \Repas\Shared\Domain\Exception\DomainException l'ingrédient n'existe pas ou est à un autre utilisateur, ou la quantité est hors de
     *                                                          1 à 99 (rien n'est écrit)
     */
    public function addItem(string $userId, string $ingredientSlug, int $quantity = 1, ?string $unitSlug = null): AddedItem;

    /**
     * Retire `$quantity` de la ligne de cet ingrédient dans la liste ACTIVE (jamais une liste envoyée au drive, jamais de liste créée) : la ligne
     * disparaît quand la quantité retirée est au moins celle qu'elle contient. Même règle d'unité que l'ajout : l'unité dite est convertie dans
     * celle de la ligne, ou retirée telle quelle si aucune conversion n'existe ; une unité que l'ingrédient ne connaît pas est laissée de côté.
     *
     * @param string  $ingredientSlug un ingrédient de l'utilisateur, ou un ancien slug que ses alias traduisent
     * @param int     $quantity       combien, de 1 à 99
     * @param ?string $unitSlug       l'unité dite
     *
     * @return RemovedItem `found` faux (rien n'est écrit) s'il n'y a pas de liste active ou pas de ligne pour cet ingrédient
     *
     * @throws \Repas\Shared\Domain\Exception\DomainException la quantité est hors de 1 à 99 (rien n'est écrit)
     */
    public function removeItem(string $userId, string $ingredientSlug, int $quantity = 1, ?string $unitSlug = null): RemovedItem;
}
