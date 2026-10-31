<?php

namespace Repas\Drive\Application\Port;

use Repas\Drive\Domain\Model\ShoppingListForDrive;

/**
 * Ce que le plan d'envoi et l'estimation du coût demandent à la liste de courses : ses lignes, avec l'ingrédient, l'unité d'achat (dimension et
 * facteur de base) et le rayon (docs/drive/architecture.md § 5.3). Adapté depuis le contexte `Repas` (ADR 0035).
 */
interface ShoppingListQueryPort
{
    /**
     * Les lignes sont triées par slug d'ingrédient ; une ligne dont l'ingrédient ou l'unité a disparu est ignorée.
     *
     * @throws \Repas\Shared\Domain\Exception\DomainException liste inconnue (404)
     */
    public function get(string $shoppingListId): ShoppingListForDrive;
}
