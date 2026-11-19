<?php

namespace Repas\Repas\Domain\Interface;

use DateTimeImmutable;
use Repas\Repas\Domain\Model\ShoppingListArchiveCause;

/**
 * Les archives des listes de courses supprimées (ADR 0041) : une copie à plat, sans lien avec les autres tables, pour comprendre après coup ce qui
 * s'est passé sur une liste (lignes cochées à la main ou par le drive, articles refusés par le drive). Écrire seulement : rien ne la relit dans l'application,
 * elle se lit en SQL.
 */
interface ShoppingListArchiveRepository
{
    /**
     * Copie la liste, ses lignes (avec le nom de l'ingrédient et le symbole de l'unité) et ses repas (avec le nom de la recette) dans une archive
     * `$archiveId`. À appeler AVANT de supprimer la liste. Une liste inconnue n'écrit rien.
     */
    public function archive(string $archiveId, string $shoppingListId, ShoppingListArchiveCause $cause, DateTimeImmutable $at): void;

    /**
     * Efface les archives d'un utilisateur (suppression de son compte, ADR 0021 : comme s'il n'avait jamais existé).
     */
    public function deleteByOwner(string $ownerId): void;
}
