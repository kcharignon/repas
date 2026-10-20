<?php

namespace Repas\Repas\Infrastructure\Visibility;


use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Repas\Infrastructure\Entity\IngredientAlias;

/**
 * LA règle de visibilité des ingrédients (ADR 0028 décision 3), écrite une seule fois et appliquée par chaque requête qui liste ou
 * choisit des ingrédients pour un utilisateur (loaders et repositories, en DQL) :
 *
 *     un ingrédient est visible par U si  creator = U
 *                                      ou (c'est un commun ET U n'a pas adopté ce commun).
 *
 * « U a adopté le commun C » = U a un alias dont la clé est C ET l'ingrédient qu'il désigne existe et lui appartient. Cette dernière
 * condition ne se contourne pas : un alias orphelin (laissé par le code d'avant ING-001 qui supprime une copie sans effacer l'alias,
 * après un retour arrière) ne doit pas faire disparaître un commun sans rien mettre à sa place. Le même sens est celui de
 * `IngredientAliasPostgreSQLRepository` (le SQL natif des alias, dont les résolutions d'alias en écriture) ; les deux sont
 * comparés par `tests/Repas/OutputAdapter/IngredientVisibilityTest`.
 *
 * Un administrateur n'a aucun alias (il administre les communs, ADR 0028 décision a) : rien n'est masqué pour lui. Un utilisateur qui
 * n'a rien adopté non plus : la règle ne change alors rien à ce que `creator = U ou vide` donnait avant elle.
 *
 * C'est aussi l'unique endroit des requêtes où la notion de « commun » est écrite (`creator` vide) : quand les communs auront un
 * propriétaire (ING-009), c'est ici que cela change. Ailleurs, un commun se reconnaît à `Ingredient::getCreatorId() === null` (domaine)
 * ou à la colonne affichée par un loader (`canEdit`).
 *
 * Le masquage est une sous-requête NON corrélée (`slug NOT IN (…)`), pas un `NOT EXISTS` corrélé : PostgreSQL l'exécute pareil (une seule
 * lecture des alias de l'utilisateur, puis une table de hachage), mais il estime le `NOT EXISTS` corrélé ligne à ligne, à un coût qui
 * franchit `jit_above_cost` dès quelques milliers d'ingrédients et déclenche la compilation JIT (de l'ordre de 150 ms pour une requête
 * de 1 ms, mesuré sur 8 000 ingrédients et 8 000 alias). `alias_slug` n'est jamais nul : le piège des `NULL` de `NOT IN` ne s'applique pas.
 */
final class IngredientVisibility
{
    /**
     * DQL : l'ingrédient désigné par `$ingredient` est un commun.
     */
    public static function isCommon(string $ingredient): string
    {
        return $ingredient . '.creatorId IS NULL';
    }

    /**
     * SQL natif : la ligne désignée par l'alias de table `$ingredientAlias` (une ligne de `ingredient`) est un commun. Même notion que `isCommon()`, pour les
     * requêtes natives de la migration d'adoption (ING-003) : si les communs reçoivent un propriétaire (ING-009), les deux changent ici.
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
     * ingrédients dans les deux formes de la base. Le « ou vide » disparaît ici avec les communs (ING-014).
     */
    public static function isCatalogSql(string $ingredientAlias, string $ownerParameter): string
    {
        return sprintf('(%1$s.creator = :%2$s OR %3$s)', $ingredientAlias, $ownerParameter, self::isCommonSql($ingredientAlias));
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
     * DQL : l'utilisateur du paramètre nommé `$viewerParameter` (sans les deux-points) voit l'ingrédient désigné par `$ingredient`.
     */
    public static function visibleBy(string $ingredient, string $viewerParameter): string
    {
        return sprintf(
            '(%1$s.creatorId = :%2$s OR (%3$s AND %1$s.slug NOT IN (%4$s)))',
            $ingredient,
            $viewerParameter,
            self::isCommon($ingredient),
            self::slugsAdoptedBy($viewerParameter),
        );
    }

    /**
     * DQL : les slugs des communs que l'utilisateur a adopté (ceux dont il a un alias vers un ingrédient qui existe et qui est à lui).
     */
    private static function slugsAdoptedBy(string $viewerParameter): string
    {
        return sprintf(
            'SELECT visibility_alias.aliasSlug FROM %1$s visibility_alias, %2$s visibility_copy'
            . ' WHERE visibility_alias.userId = :%3$s AND visibility_copy.slug = visibility_alias.ingredientSlug AND visibility_copy.creatorId = :%3$s',
            IngredientAlias::class,
            Ingredient::class,
            $viewerParameter,
        );
    }
}
