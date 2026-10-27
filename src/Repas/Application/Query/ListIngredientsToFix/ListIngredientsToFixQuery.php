<?php

namespace Repas\Repas\Application\Query\ListIngredientsToFix;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListIngredientsToFixQuery implements QueryInterface
{
    /**
     * @param string $userId l'utilisateur du jeton : seuls SES ingrédients sont jugés, sans exception pour un administrateur
     */
    public function __construct(
        public string $userId,
    ) {
    }
}
