<?php

namespace Repas\Drive\Infrastructure\Acl;

use Repas\Drive\Application\Port\ShoppingListQueryPort;
use Repas\Drive\Domain\Model\ShoppingListForDrive;
use Repas\Drive\Domain\Model\ShoppingListLineForDrive;
use Repas\Repas\Application\OpenHostService\ShoppingListContentLineDTO;
use Repas\Repas\Application\OpenHostService\ShoppingListContentQueryOHS;

/**
 * Couche anticorruption (ADR 0035) : le contenu de la liste de courses que le contexte `Repas` publie pour qui lit devient la liste que le
 * plan d'envoi et l'estimation attendent. Une liste inconnue lève l'exception de `Repas` (404), telle quelle.
 */
final readonly class ShoppingListQueryAdapter implements ShoppingListQueryPort
{
    public function __construct(
        private ShoppingListContentQueryOHS $lists,
    ) {
    }

    public function get(string $shoppingListId): ShoppingListForDrive
    {
        $list = $this->lists->get($shoppingListId);

        return new ShoppingListForDrive(
            $list->id,
            $list->ownerId,
            $list->status,
            $list->version,
            array_map(
                static fn(ShoppingListContentLineDTO $line): ShoppingListLineForDrive => new ShoppingListLineForDrive(
                    $line->ingredientSlug,
                    $line->ingredientName,
                    $line->quantity,
                    $line->unitSlug,
                    $line->unitName,
                    $line->unitSymbol,
                    $line->unitDimension,
                    $line->unitBaseFactor,
                    $line->departmentSlug,
                ),
                $list->lines,
            ),
        );
    }
}
