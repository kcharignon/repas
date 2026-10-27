<?php

namespace Repas\Repas\Infrastructure\Visibility;


/**
 * LA règle de visibilité des ingrédients (ADR 0028 et son amendement du 2026-10-20), écrite une seule fois et appliquée par chaque
 * requête qui liste ou choisit des ingrédients pour un utilisateur (loaders et repositories, en DQL) :
 *
 *     un ingrédient est visible par U si  creator = U.
 *
 * C'est tout. Il n'y a plus d'ingrédient commun : chaque compte a ses ingrédients (les anciens communs sont ceux du propriétaire du
 * catalogue, copiés aux autres comptes à leur activation) et un administrateur ne voit pas ceux des autres. Plus de masquage par alias :
 * un alias ne sert plus qu'à résoudre un ancien slug (`IngredientAliasRepository`).
 *
 * Les fragments SQL natifs `isCatalogSql()` et `isBaseCatalogSql()` ne sont pas une règle de visibilité : ils disent quels ingrédients du
 * catalogue du propriétaire part-on copier à l'amorçage d'un compte (`SeedingSourceReader`, `IngredientSeedingReportPostgreSQLLoader`).
 */
final class IngredientVisibility
{
    /**
     * SQL natif : la ligne désignée par l'alias de table `$ingredientAlias` fait partie du CATALOGUE du propriétaire, celui qu'on copie aux
     * comptes à leur amorçage (ING-008, `docs/ingredients/sans-commun.md` § 2.2) : son créateur est le propriétaire du catalogue
     * (`:ownerParameter`, sans les deux-points). C'est l'UNIQUE endroit où cette règle est écrite.
     */
    public static function isCatalogSql(string $ingredientAlias, string $ownerParameter): string
    {
        return sprintf('(%1$s.creator = :%2$s)', $ingredientAlias, $ownerParameter);
    }

    /**
     * SQL natif : un ingrédient du catalogue (`isCatalogSql()`) qui porte le marqueur « de base », c'est-à-dire qui part aux nouveaux comptes.
     * Un marqueur posé sur l'ingrédient d'un autre compte (un accès direct à la base) est inerte : la règle exige le catalogue.
     */
    public static function isBaseCatalogSql(string $ingredientAlias, string $ownerParameter): string
    {
        return sprintf('(%s AND %s.is_base)', self::isCatalogSql($ingredientAlias, $ownerParameter), $ingredientAlias);
    }

    /**
     * SQL natif : la ligne désignée par l'alias de table `$ingredientAlias` est un ingrédient de l'utilisateur du paramètre nommé
     * `$userParameter` (sans les deux-points). C'est `visibleBy()` pour une requête native (la jointure d'un résolveur de slugs).
     */
    public static function ownedSql(string $ingredientAlias, string $userParameter): string
    {
        return sprintf('%1$s.creator = :%2$s', $ingredientAlias, $userParameter);
    }

    /**
     * DQL : l'utilisateur du paramètre nommé `$viewerParameter` (sans les deux-points) voit l'ingrédient désigné par `$ingredient` : il est à
     * lui. Les ingrédients d'un autre compte, un administrateur compris, n'existent pas pour lui.
     */
    public static function visibleBy(string $ingredient, string $viewerParameter): string
    {
        return sprintf('%1$s.creatorId = :%2$s', $ingredient, $viewerParameter);
    }
}
