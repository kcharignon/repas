<?php

namespace Repas\Repas\Application\Query\ListDuplicateIngredients;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListDuplicateIngredientsQuery implements QueryInterface
{
    /**
     * @param string $userId l'utilisateur du jeton : seuls SES ingrédients sont comparés, sans exception pour un administrateur
     */
    public function __construct(
        public string $userId,
    ) {
    }
}
