<?php

namespace Repas\Repas\Domain\Model;


/**
 * Ce que dit un alias d'ingrédient (ADR 0028, `ingredient_alias.kind`) : d'où vient la correspondance « pour cet utilisateur, ce
 * slug est cet ingrédient ».
 */
enum AliasKind: string
{
    /** Le slug d'alias est celui d'un ingrédient du catalogue dont l'utilisateur a reçu une copie (amorçage, migration) : l'ancien slug se résout vers elle. */
    case ADOPTION = 'adoption';

    /** Le slug d'alias est celui d'un ingrédient de l'utilisateur absorbé par une fusion (ING-005). */
    case MERGE = 'merge';
}
