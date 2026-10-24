<?php

namespace Repas\Repas\Domain\Model;


use Repas\Repas\Domain\Exception\IngredientException;

/**
 * Pourquoi la fusion de deux ingrédients de l'utilisateur est refusée (ADR 0028 décision 9, `docs/ingredients/fusion.md` § 2). L'aperçu
 * en dit un seul, le PREMIER dans l'ordre ci-dessous ; l'écriture lève l'exception de ce même cas, et rien n'est écrit.
 *
 * L'ordre est celui des cases : un refus que l'utilisateur ne peut pas lever (le même ingrédient) passe avant ceux qu'il peut lever
 * (rouvrir la liste envoyée, ajouter l'unité qui manque). Il n'y a plus de cas « ingrédient commun » (amendement du 2026-10-20) : tout
 * ingrédient est à quelqu'un ; la valeur `COMMON_INGREDIENT` reste seulement déclarée dans la documentation de l'API, pour les clients
 * existants, et n'est plus jamais rendue.
 */
enum MergeBlock: string
{
    /** Les deux ingrédients sont le même. */
    case SAME_INGREDIENT = 'SAME_INGREDIENT';

    /** Une ligne d'un autre utilisateur référence l'ingrédient absorbé (donnée héritée d'avant l'ADR 0021). */
    case USED_BY_ANOTHER_USER = 'USED_BY_ANOTHER_USER';

    /** Une liste envoyée au drive utilise l'un des deux : elle est immuable (ADR 0023). */
    case IN_SENT_LIST = 'IN_SENT_LIST';

    /** Une unité utilisée par l'ingrédient absorbé ne se convertit pas par l'ingrédient gardé. */
    case UNIT_MISSING = 'UNIT_MISSING';

    public function toException(): IngredientException
    {
        return match ($this) {
            self::SAME_INGREDIENT => IngredientException::cannotMergeIntoItself(),
            self::USED_BY_ANOTHER_USER => IngredientException::usedByAnotherUser(),
            self::IN_SENT_LIST => IngredientException::inSentList(),
            self::UNIT_MISSING => IngredientException::mergeUnitMissing(),
        };
    }
}
