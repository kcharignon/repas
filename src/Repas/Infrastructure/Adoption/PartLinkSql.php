<?php

namespace Repas\Repas\Infrastructure\Adoption;


/**
 * LA règle de liaison des sous-ingrédients, écrite à un seul endroit (ADR 0038 décision 21) : les liens que « relier les copies » poserait.
 * Trois lecteurs partagent ce texte pour dire la même chose, jamais trois formules : l'instruction qui écrit
 * (`IngredientPartLinkPostgreSQLRepository`), la requête de santé de `repas:ingredients:seed --check` (« copies à relier ») et le rapport à
 * blanc (le nombre de liens qu'`--apply` poserait).
 *
 * Un lien est une paire (enfant `c`, parent `pc`) du MÊME compte :
 *
 * - `c` n'a pas de parent, n'a pas de partie à lui et n'a aucune ligne dans une liste non terminée ; son origine `o` (`c.adopted_from`) est une
 *   partie (`parent_slug`) de `po` ;
 * - `pc` est un ingrédient du compte dont l'origine est celle de `po` (`COALESCE(po.adopted_from, po.slug)`), qui n'est pas une partie et dont
 *   l'origine n'en est pas une non plus : ni `c` ni `pc` ne peuvent être, dans la même instruction, l'un le parent de l'autre (jamais de chaîne) ;
 * - si le compte a plusieurs candidats pour `pc` (un doublon), le plus petit slug : l'instruction est déterministe, donc rejouable ;
 * - un parent garde au plus `MAX_PARTS` parties, celles qu'il a et celles que cette instruction lui donne (les enfants dans l'ordre de leur slug).
 *
 * Le ratio est celui de l'origine. Les paramètres : `:max` (le plafond de parties) ; `:u` (le compte) pour `ofOneAccount`, `:marmiton` (le
 * propriétaire réservé des recettes Marmiton, jamais relié) pour `ofEveryAccount` ; `:virtual` (un tableau JSON de slugs d'origine) pour les
 * copies que le rapport à blanc imagine, avec `withVirtualCopies`.
 */
final class PartLinkSql
{
    /**
     * Les liens à poser pour un compte : `child`, `parent`, `ratio`.
     */
    public static function ofOneAccount(bool $withVirtualCopies = false): string
    {
        return self::links('i.creator = :u', $withVirtualCopies ? ' UNION ALL ' . self::virtualCopies() : '');
    }

    /**
     * Les liens à poser pour tous les comptes (la requête de santé) : `child`, `parent`, `ratio`.
     */
    public static function ofEveryAccount(): string
    {
        return self::links('i.creator <> :marmiton', '');
    }

    /**
     * Les ingrédients que le rapport à blanc imagine copiés pour le compte `:u`, un par origine de `:virtual` : sans parent, sans partie, sans
     * ligne, avec la provenance qu'aurait leur copie. Leur slug ne peut être celui d'aucun ingrédient (il contient « : »).
     */
    private static function virtualCopies(): string
    {
        return "SELECT 'virtual:' || v.value AS slug, CAST(:u AS VARCHAR(255)) AS creator, v.value::VARCHAR(255) AS adopted_from, CAST(NULL AS VARCHAR(255)) AS parent_slug"
            . ' FROM jsonb_array_elements_text(CAST(:virtual AS jsonb)) AS v(value)';
    }

    private static function links(string $scope, string $virtual): string
    {
        $own = 'SELECT i.slug, i.creator, i.adopted_from, i.parent_slug FROM ingredient i WHERE ' . $scope . $virtual;

        // Une paire candidate par enfant (le parent au plus petit slug), puis le plafond de parties par parent
        $pairs = 'SELECT DISTINCT ON (c.slug) c.slug AS child, c.creator AS creator, pc.slug AS parent, o.parent_ratio AS ratio'
            . ' FROM (' . $own . ') c'
            . ' JOIN ingredient o ON o.slug = c.adopted_from AND o.parent_slug IS NOT NULL AND o.parent_ratio IS NOT NULL'
            . ' JOIN ingredient po ON po.slug = o.parent_slug'
            . ' JOIN (' . $own . ') pc ON pc.creator = c.creator AND pc.adopted_from = COALESCE(po.adopted_from, po.slug) AND pc.parent_slug IS NULL AND pc.slug <> c.slug'
            . ' WHERE c.parent_slug IS NULL'
            // `c` n'a pas de partie à lui : il ne devient pas une partie d'une partie
            . ' AND NOT EXISTS (SELECT 1 FROM ingredient k WHERE k.creator = c.creator AND k.parent_slug = c.slug)'
            // l'origine de `pc` n'est pas une partie : `pc` ne sera jamais un enfant de cette instruction
            . ' AND NOT EXISTS (SELECT 1 FROM ingredient b WHERE b.slug = pc.adopted_from AND b.parent_slug IS NOT NULL)'
            // une partie n'est jamais une ligne de liste en cours (ADR 0038 décision 6) : comme `PUT /ingredients/{slug}/parent`, `409 INGREDIENT_PART_IN_LIST`
            . " AND NOT EXISTS (SELECT 1 FROM shopping_list_row r JOIN shopping_list l ON l.id = r.shopping_list WHERE r.ingredient = c.slug AND l.status <> 'COMPLETED')"
            . ' ORDER BY c.slug, pc.slug';

        return 'SELECT p.child, p.parent, p.ratio FROM ('
            . 'SELECT q.*, (SELECT count(*) FROM ingredient k WHERE k.creator = q.creator AND k.parent_slug = q.parent) AS existing,'
            . ' row_number() OVER (PARTITION BY q.parent ORDER BY q.child) AS position'
            . ' FROM (' . $pairs . ') q'
            . ') p WHERE p.existing + p.position <= :max';
    }
}
