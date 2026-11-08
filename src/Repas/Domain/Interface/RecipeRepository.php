<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Domain\Tool\Tab;

interface RecipeRepository
{
    public function findOneById(string $id): Recipe;

    /**
     * Les recettes de ces identifiants, EN LOT : les recettes, leurs lignes, leurs ingrédients, unités et types se lisent en une lecture chacun,
     * quel que soit le nombre d'identifiants (ADR 0040 décision 12 : jamais une lecture par recette utilisée). Un identifiant inconnu est absent
     * du résultat, sans erreur (une arête dont la recette utilisée n'existe plus est tolérée, décision 21).
     *
     * Les modèles rendus n'ont **pas** leurs composants (les arêtes ne sont pas lues : la profondeur est 1) ; `hasComponents()` dit pourtant s'ils
     * en ont. Ils servent à lire (aplatir, juger une règle), jamais à être enregistrés : le drapeau « composants modifiés » est faux.
     *
     * @param list<string> $ids
     * @return array<string, Recipe> par identifiant
     */
    public function findByIds(array $ids): array;

    /**
     * @return Tab<Recipe>
     */
    public function findByAuthor(string $authorId): Tab;

    /**
     * @return Tab<Recipe>
     */
    public function findBy(array $criteria, ?array $orderBy = null): Tab;

    /**
     * Enregistre la recette et ses lignes. Ses composants (ADR 0040 décision 25) ne sont écrits (les absents effacés, les autres écrits, avec leurs
     * positions, et `recipe.component_count`) que si `Recipe::areComponentsChanged()` : un modèle chargé puis enregistré sans y toucher ne les
     * efface jamais, et ne les lit pas non plus. Une recette neuve écrit ceux qu'elle porte. Pour remplacer les composants, garder l'identifiant
     * d'une arête dont la paire (recette composée, recette utilisée) existe déjà : une arête de la même paire sous un autre identifiant est
     * effacée avant d'être écrite, sans conflit.
     */
    public function save(Recipe $recipe): void;

    /**
     * L'identifiant de la recette neutre de cet identifiant Marmiton (ADR 0037 décision 3), ou `null` si elle n'a jamais été importée. Une seule
     * requête, sans hydrater la recette : l'import ne lit la neutre en entier que s'il doit la copier.
     */
    public function findMarmitonNeutralId(string $marmitonId): ?string;

    /**
     * L'identifiant de la copie que cet auteur a faite de cette recette (la recette dont `original_id` est `$originalId`), ou `null` s'il n'en a
     * pas : c'est ce qui rend l'import d'un même lien idempotent (ADR 0037 décision 9). Une seule requête, sans hydrater la recette.
     */
    public function findCopyIdOf(string $originalId, string $authorId): ?string;

    /**
     * L'auteur a-t-il au moins `$count` recettes ? La lecture s'arrête à la `$count`-ième : jamais un décompte complet (plafond de recettes,
     * `Recipe::MAX_PER_AUTHOR`).
     */
    public function authorHasAtLeast(string $authorId, int $count): bool;

    /**
     * Une recette utilise-t-elle cet ingrédient ? (existence seulement : aucun agrégat n'est hydraté)
     */
    public function isIngredientUsed(string $ingredientSlug): bool;

    /**
     * Une recette en utilise-t-elle cette recette (ADR 0040 décisions 6 et 31) ? Existence seulement, jamais un `COUNT` : une ligne lue sur l'index
     * des recettes utilisées. Une arête dont la recette composée n'existe plus compte (aucune clé étrangère) : un orphelin se corrige, il ne
     * s'ignore pas ici.
     */
    public function isUsedAsComponent(string $recipeId): bool;

    /**
     * Une recette ORDINAIRE (non secrète) utilise-t-elle cette recette ? C'est ce qui interdit de la rendre secrète (ADR 0040 décision 8).
     * Existence seulement : l'arête jointe à la recette composée, `secret = false`.
     */
    public function isUsedByOrdinaryRecipe(string $recipeId): bool;

    /**
     * Cette recette utilise-t-elle une recette SECRÈTE ? C'est ce qui interdit de la rendre publique (ADR 0040 décision 8). Existence seulement :
     * l'arête jointe à la recette utilisée, `secret = true`.
     */
    public function hasSecretComponent(string $recipeId): bool;

    /**
     * Supprime la recette, ses lignes et, si elle en a (`hasComponents()`), ses arêtes : jamais les recettes qu'elle utilisait (ADR 0040
     * décision 31). Refuser de supprimer une recette qui sert (`isUsedAsComponent`) est le rôle du cas d'usage.
     */
    public function delete(Recipe $recipe): void;

    /**
     * Identifiants seulement : de quoi agir sur les recettes d'un auteur sans les hydrater.
     *
     * @return list<string>
     */
    public function findIdsByAuthor(string $authorId): array;

    /**
     * Les copies de ces recettes deviennent des recettes originales (`originalId` à `null`, version augmentée), comme si
     * leur auteur les avait écrites : l'original disparaît (suppression d'un compte, ADR 0021). Opération en masse : les
     * agrégats déjà chargés ne sont pas mis à jour.
     *
     * @param list<string> $recipeIds
     */
    public function makeCopiesOriginal(array $recipeIds): void;

    /**
     * Supprime toutes les recettes d'un auteur, leurs lignes et leurs arêtes de composants (suppression d'un compte, ADR 0021 et 0040 décision 37).
     * Opération en masse :
     * ni contrôle de version ni événement par recette, et les agrégats déjà chargés ne sont pas mis à jour.
     */
    public function deleteByAuthor(string $authorId): void;

    /**
     * Toutes les recettes de ce type, de tous les auteurs, passent dans l'autre type (suppression d'un type de recette). Leur version
     * augmente : leur contenu a changé. Opération en masse : ni contrôle de version ni événement par recette, et les agrégats déjà
     * chargés ne sont pas mis à jour.
     */
    public function moveToType(string $fromTypeSlug, string $toTypeSlug): void;
}
