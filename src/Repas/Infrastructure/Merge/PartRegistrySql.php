<?php

namespace Repas\Repas\Infrastructure\Merge;


/**
 * LA question « le registre des parties de cette liste nomme-t-il cet ingrédient ? » (ADR 0038 décisions 6, 25 et 26), écrite à un seul endroit :
 * la fusion (`IngredientMergeUsageReader`, qui la pose pour les deux ingrédients) et la suppression (`ShoppingListPostgreSQLRepository::isIngredientUsed`)
 * la partagent, jamais deux formules.
 *
 * Le registre (`shopping_list.part_shares`, JSONB) est `{ "<partie>": { "parent": "<parent>", "quantity": …, "equivalent": … } }` : un ingrédient y est
 * NOMMÉ quand il est la clé (la partie) ou la valeur de `parent` d'une entrée (le parent). Le registre est une entrée non fiable
 * (`PartShare::registryFrom`) : tout ce qui n'est pas un objet est lu comme vide, une entrée qui n'est pas un objet ne nomme personne. La requête ne
 * lève jamais d'erreur de type JSON, quoi que la colonne contienne.
 */
final class PartRegistrySql
{
    /**
     * Une expression booléenne : le registre de la liste `$list` (l'alias de `shopping_list` dans la requête) nomme l'ingrédient du paramètre
     * `$slugParameter` (par exemple `:slug`), en partie ou en parent. Faux pour une liste sans registre.
     */
    public static function mentions(string $list, string $slugParameter): string
    {
        $registry = sprintf("(CASE WHEN jsonb_typeof(%s.part_shares) = 'object' THEN %s.part_shares ELSE CAST('{}' AS jsonb) END)", $list, $list);

        return sprintf(
            '(jsonb_exists(%1$s, %2$s) OR EXISTS (SELECT 1 FROM jsonb_each(%1$s) AS share WHERE share.value ->> \'parent\' = %2$s))',
            $registry,
            $slugParameter,
        );
    }
}
