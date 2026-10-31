<?php

namespace Repas\Repas\Application\OpenHostService;

use Repas\Repas\Application\Loader\ShoppingListContentLoader;
use Repas\Repas\Domain\Exception\ShoppingListException;

/**
 * Open Host Service de lecture du contexte `Repas` (ADR 0035) : le contenu d'une liste de courses (ses lignes avec l'ingrédient, l'unité
 * d'achat et le rayon), pour un autre contexte qui la traduit en autre chose (le plan d'envoi au drive, l'estimation de son coût).
 */
final readonly class ShoppingListContentQueryOHS
{
    public function __construct(
        private ShoppingListContentLoader $lists,
    ) {
    }

    /**
     * Les lignes sont triées par slug d'ingrédient ; une ligne dont l'ingrédient ou l'unité a disparu est ignorée.
     *
     * @throws ShoppingListException liste inconnue (404)
     */
    public function get(string $shoppingListId): ShoppingListContentDTO
    {
        $list = $this->lists->get($shoppingListId);

        return new ShoppingListContentDTO(
            $list['id'],
            $list['ownerId'],
            $list['status'],
            $list['version'],
            array_map(
                static fn(array $line): ShoppingListContentLineDTO => new ShoppingListContentLineDTO(
                    $line['ingredientSlug'],
                    $line['ingredientName'],
                    $line['quantity'],
                    $line['unitSlug'],
                    $line['unitName'],
                    $line['unitSymbol'],
                    $line['unitDimension'],
                    $line['unitBaseFactor'],
                    $line['departmentSlug'],
                ),
                $list['lines'],
            ),
        );
    }
}
