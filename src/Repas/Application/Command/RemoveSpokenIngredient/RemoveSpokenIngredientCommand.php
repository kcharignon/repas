<?php

namespace Repas\Repas\Application\Command\RemoveSpokenIngredient;

/**
 * Retire un ingrédient dit à voix haute de la liste active de l'utilisateur (ADR 0029 décision 12). Le handler renvoie un
 * `Repas\Domain\Model\RemovedItem` (exception à « une commande ne renvoie rien », ADR 0015 §12 : le résultat dit à l'appelant ce qui
 * reste dans la ligne, de quoi choisir la phrase de réponse).
 */
final readonly class RemoveSpokenIngredientCommand
{
    public const int MIN_QUANTITY = 1;
    public const int MAX_QUANTITY = 99;

    /**
     * @param int     $quantity combien d'unités (de 1 à 99), dans l'unité dite ou, sans unité dite, dans celle de la ligne
     * @param ?string $unitSlug l'unité dite, convertie dans celle de la ligne quand l'ingrédient la connaît
     */
    public function __construct(
        public string $ownerId,
        public string $ingredientSlug,
        public int $quantity = 1,
        public ?string $unitSlug = null,
    ) {
    }
}
