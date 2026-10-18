<?php

namespace Repas\Shared\Application\Interface;

use Repas\Shared\Application\Dto\ShoppingListForDrive;
use Repas\Shared\Domain\Exception\DomainException;

/**
 * Port de lecture d'une liste de courses pour le contexte `Drive`, qui n'importe pas `Repas` : défini ici, implémenté dans
 * `Repas\Infrastructure\Loader` (docs/drive/architecture.md § 5.3).
 */
interface ShoppingListForDriveLoader
{
    /**
     * Les lignes sont triées par slug d'ingrédient ; une ligne dont l'ingrédient ou l'unité a disparu est ignorée.
     *
     * @throws DomainException liste inconnue (404)
     */
    public function get(string $shoppingListId): ShoppingListForDrive;
}
