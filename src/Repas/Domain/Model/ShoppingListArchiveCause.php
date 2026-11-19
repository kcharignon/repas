<?php

namespace Repas\Repas\Domain\Model;

/**
 * Pourquoi une liste de courses a été copiée dans les archives avant d'être supprimée (ADR 0041).
 */
enum ShoppingListArchiveCause: string
{
    /** La personne a supprimé la liste (`DELETE /shopping-lists/{id}`). */
    case DELETED_BY_USER = 'DELETED_BY_USER';
    /** La liste était terminée : elle part quand la personne en crée une nouvelle (ADR 0036). */
    case CLEANED_AFTER_COMPLETION = 'CLEANED_AFTER_COMPLETION';
}
