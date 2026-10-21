<?php

namespace Repas\Repas\Domain\Service;


use Repas\Shared\Domain\Tool\StringTool;

/**
 * Le slug d'un ingrédient adopté (ADR 0028 décision 1, `docs/ingredients/migration.md` § 5) : le motif des ingrédients personnels,
 * `slugify(nom . identifiant de l'utilisateur)` (`Ingredient::create()`), puis `…-2…`, `…-3…` en cas de collision, jusqu'au premier
 * libre. Une collision n'est jamais une fusion : l'utilisateur garde les deux ingrédients, un doublon visible qu'il peut fusionner.
 * Pur : ni base ni horloge, la disponibilité d'un slug est donnée par l'appelant.
 */
final readonly class IngredientSlugPolicy
{
    /**
     * Le premier slug libre pour la copie de l'ingrédient nommé `$name` chez `$userId` : `tomate<U>`, puis `tomate-2<U>`, `tomate-3<U>`…
     *
     * Le premier candidat est EXACTEMENT celui que `Ingredient::create($name, …, $userId)` produirait, calculé sur le nom et non sur
     * `slugged_name . U` : les deux diffèrent quand le nom finit par un symbole (« couche (taille 4) » donne `couche-taille-4-<U>`
     * à la création, `couche-taille-4<U>` en collant), et un ingrédient adopté doit avoir le slug qu'aurait l'ingrédient créé au même nom
     * (sans quoi la collision avec un ingrédient personnel de même nom passerait inaperçue). Pour un nom qui finit par une lettre ou un
     * chiffre, c'est `slugged_name . U`, `slugged_name . '-2' . U`.
     *
     * @param string $name le nom de l'ingrédient copié (pas son `slugged_name`)
     * @param array<string>|callable(string): bool $taken les slugs déjà pris, ou une fonction qui dit si un slug l'est (une question
     *        d'existence posée au port, sans hydrater d'ingrédient)
     */
    public function forAdoption(string $name, string $userId, array|callable $taken): string
    {
        $isTaken = is_array($taken)
            ? static fn(string $slug): bool => in_array($slug, $taken, true)
            : $taken;

        $slug = StringTool::slugify($name . $userId);
        for ($attempt = 2; $isTaken($slug); ++$attempt) {
            $slug = StringTool::slugify($name . '-' . $attempt . $userId);
        }

        return $slug;
    }
}
