<?php

namespace Repas\Repas\Application\Command\AddSpokenIngredient;

/**
 * Ajoute un ingrédient dit à voix haute à la liste active de l'utilisateur, en créant la liste s'il n'en a pas (ADR 0029 décision 5).
 * Le handler renvoie un `Shared\Application\Interface\AddedItem` (exception à « une commande ne renvoie rien », ADR 0015 §12 : le
 * résultat dit à l'appelant s'il faut annoncer la création d'une liste).
 */
final readonly class AddSpokenIngredientCommand
{
    /**
     * @param string $newShoppingListId identifiant de la liste créée si l'utilisateur n'en a pas d'active
     */
    public function __construct(
        public string $ownerId,
        public string $ingredientSlug,
        public string $newShoppingListId,
    ) {
    }
}
