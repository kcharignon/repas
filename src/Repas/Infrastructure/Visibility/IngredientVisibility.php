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
 * Les fragments SQL `isCommonSql()`, `isCatalogSql()` et `isBaseCatalogSql()` ne sont PAS une règle de visibilité : ils appartiennent aux
 * commandes d'exploitation de la migration (`repas:ingredients:adopt-commons`, `seed`, `hand-over-catalog`), qui lisent encore la forme
 * de la base d'avant la passation (ingrédients sans créateur) pour servir le retour arrière et le rattrapage. Ils disparaissent avec
 * elles (ING-014).
 */
final class IngredientVisibility
{
    /**
     * SQL natif : la ligne désignée par l'alias de table `$ingredientAlias` (une ligne de `ingredient`) n'a pas de créateur. Réservé aux
     * requêtes natives des commandes d'exploitation de la migration (ING-003, ING-008, ING-009), qui lisent la forme de la base d'avant la
     * passation ; aucune lecture d'utilisateur ne s'en sert (ING-014 le supprime).
     */
    public static function isCommonSql(string $ingredientAlias): string
    {
        return $ingredientAlias . '.creator IS NULL';
    }

    /**
     * SQL natif : la ligne désignée par l'alias de table `$ingredientAlias` fait partie du CATALOGUE du propriétaire, celui qu'on copie aux
     * comptes à leur amorçage (ING-008, `docs/ingredients/sans-commun.md` § 2.2) : son créateur est le propriétaire du catalogue
     * (`:ownerParameter`, sans les deux-points) **ou est vide**. C'est l'UNIQUE endroit où cette règle transitoire est écrite : avant la
     * passation (ING-009) les anciens communs n'ont pas de créateur, après elle ils sont au propriétaire, et la règle donne les mêmes
     * ingrédients dans les deux formes de la base. Le « ou vide » disparaît ici avec les communs (ING-014) ; sur la base d'aujourd'hui, où
     * aucun ingrédient n'est sans créateur, il ne sélectionne rien.
     */
    public static function isCatalogSql(string $ingredientAlias, string $ownerParameter): string
    {
        return sprintf('(%1$s.creator = :%2$s OR %3$s)', $ingredientAlias, $ownerParameter, self::isCommonSql($ingredientAlias));
    }

    /**
     * SQL natif : un ingrédient du catalogue (`isCatalogSql()`) qui porte le marqueur « de base », c'est-à-dire qui part aux nouveaux comptes.
     * Un marqueur posé sur l'ingrédient d'un autre compte (un accès direct à la base) est inerte : la règle exige le catalogue.
     *
     * Avec `$assumedBaseParameter` (le nom d'un paramètre tableau de slugs), la règle est lue « comme si le classement était posé » : seul le
     * rapport à blanc de la passation s'en sert, pour dire ce que l'amorçage copierait AVANT la phase 1. L'écriture lit toujours la colonne.
     */
    public static function isBaseCatalogSql(string $ingredientAlias, string $ownerParameter, ?string $assumedBaseParameter = null): string
    {
        if ($assumedBaseParameter === null) {
            return sprintf('(%s AND %s.is_base)', self::isCatalogSql($ingredientAlias, $ownerParameter), $ingredientAlias);
        }

        // Le rapport à blanc de la passation (ING-009) avant que le classement soit posé : les ingrédients sans créateur sont « de base » s'ils
        // sont dans la liste du fichier (le paramètre nommé, un tableau de slugs, jamais vide), les autres gardent leur marqueur
        return sprintf(
            '(%1$s AND ((%2$s AND %3$s.slug IN (:%4$s)) OR (NOT %2$s AND %3$s.is_base)))',
            self::isCatalogSql($ingredientAlias, $ownerParameter),
            self::isCommonSql($ingredientAlias),
            $ingredientAlias,
            $assumedBaseParameter,
        );
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
