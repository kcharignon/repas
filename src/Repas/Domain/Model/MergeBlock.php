<?php

namespace Repas\Repas\Domain\Model;


use Repas\Repas\Domain\Exception\IngredientException;

/**
 * Pourquoi la fusion de deux ingrédients de l'utilisateur est refusée (ADR 0028 décision 9, `docs/ingredients/fusion.md` § 2). L'aperçu
 * en dit un seul, le PREMIER dans l'ordre ci-dessous ; l'écriture lève l'exception de ce même cas, et rien n'est écrit.
 *
 * L'ordre est celui des cases : un refus que l'utilisateur ne peut pas lever (le même ingrédient) passe avant ceux qu'il peut lever
 * (rouvrir la liste envoyée, recoller des parties, terminer la liste en cours, ajouter l'unité qui manque). Il n'y a plus de cas « ingrédient commun » (amendement du 2026-10-20) : tout
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

    /**
     * La fusion laisserait un découpage faux (ADR 0038 décision 25) : les deux sont des parties de deux parents différents, l'un est le parent
     * de l'autre, le résultat serait à la fois une partie et un parent (une chaîne), le gardé dépasserait dix parties, ou le lien ou les
     * parties de l'absorbé ne se transportent pas d'une unité à l'autre. L'utilisateur le lève en recollant des parties (`DELETE …/parent`).
     */
    case PART_CONFLICT = 'PART_CONFLICT';

    /**
     * Le registre des parties d'une liste non terminée nomme l'un des deux (en partie ou en parent), ou le gardé serait une partie alors que l'un
     * des deux a une ligne dans une liste non terminée : la fusion ne réécrit jamais le registre (ADR 0038 décision 25). L'utilisateur le lève en
     * terminant la liste, ou en retirant les repas qui l'ont posé.
     */
    case PART_IN_LIST = 'PART_IN_LIST';

    /** Une unité utilisée par l'ingrédient absorbé ne se convertit pas par l'ingrédient gardé. */
    case UNIT_MISSING = 'UNIT_MISSING';

    public function toException(): IngredientException
    {
        return match ($this) {
            self::SAME_INGREDIENT => IngredientException::cannotMergeIntoItself(),
            self::USED_BY_ANOTHER_USER => IngredientException::usedByAnotherUser(),
            self::IN_SENT_LIST => IngredientException::inSentList(),
            self::PART_CONFLICT => IngredientException::mergePartConflict(),
            self::PART_IN_LIST => IngredientException::mergePartInList(),
            self::UNIT_MISSING => IngredientException::mergeUnitMissing(),
        };
    }
}
