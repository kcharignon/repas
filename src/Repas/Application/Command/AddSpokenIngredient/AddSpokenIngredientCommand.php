<?php

namespace Repas\Repas\Application\Command\AddSpokenIngredient;

/**
 * Ajoute un ingrédient dit à voix haute à la liste active de l'utilisateur, en créant la liste s'il n'en a pas (ADR 0029 décision 5).
 * Le handler renvoie un `Shared\Application\Interface\AddedItem` (exception à « une commande ne renvoie rien », ADR 0015 §12 : le
 * résultat dit à l'appelant s'il faut annoncer la création d'une liste, et le total de la ligne).
 */
final readonly class AddSpokenIngredientCommand
{
    public const int MIN_QUANTITY = 1;
    public const int MAX_QUANTITY = 99;

    /**
     * @param string  $newShoppingListId identifiant de la liste créée si l'utilisateur n'en a pas d'active
     * @param int     $quantity          combien d'unités (de 1 à 99), dans l'unité dite ou, sans unité dite, dans celle de la ligne
     * @param ?string $unitSlug          l'unité dite, utilisée seulement si l'ingrédient la connaît
     */
    public function __construct(
        public string $ownerId,
        public string $ingredientSlug,
        public string $newShoppingListId,
        public int $quantity = 1,
        public ?string $unitSlug = null,
    ) {
    }
}
