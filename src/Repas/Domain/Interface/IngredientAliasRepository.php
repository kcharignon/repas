<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\IngredientAlias;

/**
 * Les alias d'ingrédients (ADR 0028) : « pour cet utilisateur, `aliasSlug` est son ingrédient `ingredientSlug` ». Une ligne par
 * (utilisateur, slug d'alias). Aucune hydratation d'ingrédient : des slugs, des booléens et des lignes d'alias.
 */
interface IngredientAliasRepository
{
    public function find(string $userId, string $aliasSlug): ?IngredientAlias;

    /**
     * Les ingrédients de l'utilisateur qui répondent à ces slugs, en une requête : `[slug d'alias => slug de l'ingrédient]`.
     * Un slug sans alias est absent du résultat (l'appelant garde alors le slug reçu).
     *
     * @param list<string> $slugs
     * @return array<string, string>
     */
    public function resolve(string $userId, array $slugs): array;

    /**
     * L'utilisateur a adopté au moins un commun (alias d'adoption) : le monde « propre à l'utilisateur » (ADR 0028 décision 4).
     */
    public function isAdopter(string $userId): bool;

    /**
     * Écrit l'alias s'il n'y en a pas déjà un pour (utilisateur, slug d'alias) et ne remplace jamais le premier : deux adoptions
     * simultanées du même commun se règlent par la clé de la table. `true` si cet appel l'a écrit, `false` si l'utilisateur en
     * avait déjà un (le gagnant se relit par `find()`).
     */
    public function save(IngredientAlias $alias): bool;

    /**
     * Efface tous les alias d'un compte supprimé (ADR 0021). Opération en masse.
     */
    public function deleteByUser(string $userId): void;

    /**
     * Efface l'alias dont la clé est ce slug, pour cet utilisateur : un ingrédient (re)créé sous ce slug ne doit pas être détourné
     * par un ancien alias, par exemple de fusion.
     */
    public function deleteByAliasSlug(string $userId, string $aliasSlug): void;

    /**
     * Efface les alias de cet utilisateur qui désignent cet ingrédient, quand l'ingrédient est supprimé : le commun adopté redevient
     * visible pour lui.
     */
    public function deleteByIngredientSlug(string $userId, string $ingredientSlug): void;
}
