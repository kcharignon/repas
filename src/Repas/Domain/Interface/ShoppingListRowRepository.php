<?php

namespace Repas\Repas\Domain\Interface;


use DateTimeImmutable;
use Repas\Repas\Domain\Model\DriveLineAttempt;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Repas\Domain\Model\ShoppingListRow as ShoppingListRowModel;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Domain\Tool\Tab;

interface ShoppingListRowRepository
{
    public function findOneById(string $id): ShoppingListRow;

    public function save(ShoppingListRow $shoppingListRow): void;

    /**
     * Vrai quand plus aucune ligne de la liste n'est à cocher (une liste sans ligne l'est aussi).
     */
    public function allRowsChecked(string $shoppingListId): bool;

    /**
     * Écrit, en UNE instruction, ce que le passage au drive a fait des lignes de cette liste (DRV-745, ADR 0041) : l'issue, la raison et l'instant
     * du passage sur chaque ligne dont l'ingrédient est dans `$attempts`, et, pour une issue `IN_CART` (l'article est au panier), la coche de la
     * ligne, d'origine `DRIVE`. Ne décoche jamais rien, ne touche ni la version de la liste ni ses événements ; un slug qui n'est pas dans la liste
     * est ignoré, une ligne déjà cochée le reste et garde son origine. Aucun contrôle de droit ni de statut : c'est celui du cas d'usage.
     *
     * @param list<DriveLineAttempt> $attempts un ingrédient au plus une fois
     */
    public function recordDrivePassage(string $shoppingListId, array $attempts, DateTimeImmutable $at): void;

    /**
     * @return Tab<ShoppingListRowModel>
     */
    public function findByShoppingListId(string $shoppingListId): Tab;

    /**
     * @param Tab<string> $ids
     */
    public function deleteByShoppingListIdExceptIds(string $shoppingListId, Tab $ids): void;

    public function deleteByShoppingListId(string $shoppingListId): void;

}
