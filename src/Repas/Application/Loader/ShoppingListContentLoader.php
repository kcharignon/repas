<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Domain\Exception\ShoppingListException;

/**
 * Lecture du contenu d'une liste de courses pour un autre contexte (ses lignes NON cochées avec l'ingrédient, l'unité d'achat et le rayon :
 * ce qui est déjà coché n'est pas à acheter, DRV-745). Des valeurs simples : l'Open Host Service qui la publie les met en forme.
 */
interface ShoppingListContentLoader
{
    /**
     * Les lignes non cochées sont triées par slug d'ingrédient ; une ligne dont l'ingrédient ou l'unité a disparu est ignorée.
     *
     * @return array{id: string, ownerId: string, status: string, version: int, lines: list<array{ingredientSlug: string, ingredientName: string, quantity: float, unitSlug: string, unitName: string, unitSymbol: string, unitDimension: ?string, unitBaseFactor: ?float, departmentSlug: ?string}>}
     *
     * @throws ShoppingListException liste inconnue (404)
     */
    public function get(string $shoppingListId): array;
}
