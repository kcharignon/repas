<?php

namespace Repas\Repas\Domain\Interface;


/**
 * Le classement « de base » des 261 anciens communs, validé par le propriétaire du catalogue (ADR 0028, amendement du 2026-10-20, ING-009) :
 * 151 ingrédients « de base » (ils partent aux nouveaux comptes) et 110 « spécifiques » (ils restent chez lui). C'est une DONNÉE DE PRODUCTION
 * VALIDÉE, jamais « corrigée » par le code : le fichier de `docs/ingredients/base-ingredients.tsv` en est l'enregistrement, sa copie sous
 * `src/` (`Infrastructure/Data/base-ingredients.json`, `docs/` n'est pas dans l'image) est la source de la commande de passation.
 */
interface BaseIngredientClassification
{
    /**
     * Tous les anciens communs, dans l'ordre du fichier : `[slug => vrai s'il est « de base »]`.
     *
     * @return array<string, bool>
     */
    public function all(): array;
}
