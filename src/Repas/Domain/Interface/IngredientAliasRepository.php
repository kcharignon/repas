<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\AliasLookup;
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
     * Seul un alias VALIDE répond : l'ingrédient qu'il désigne existe et appartient à l'utilisateur. Un alias orphelin (laissé par le
     * code d'avant ING-001, qui supprime une copie sans effacer son alias, après un retour arrière) est ignoré : le slug reçu reste
     * celui du commun, qui n'est pas masqué non plus (`IngredientVisibility`).
     *
     * @param list<string> $slugs
     * @return array<string, string>
     */
    public function resolve(string $userId, array $slugs): array;

    /**
     * Comme `resolve()`, et dit en plus, dans la MÊME requête, si l'utilisateur est adoptant (`isAdopter()`) : la résolution d'un slug
     * reçu en écriture coûte ainsi une seule lecture, y compris pour celui qui n'a aucun alias (ING-002).
     *
     * @param list<string> $slugs
     */
    public function lookup(string $userId, array $slugs): AliasLookup;

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

    /**
     * La correspondance d'une fusion (ING-005, `docs/ingredients/fusion.md` § 3 étape 5) : tout alias de l'utilisateur qui désignait
     * l'ingrédient absorbé désigne désormais l'ingrédient gardé (le commun adopté reste masqué, derrière la copie qui reste), puis
     * l'alias `absorbé -> gardé` (`kind` `merge`) est écrit pour que les anciens slugs (onglet ouvert, brouillon d'envoi) se résolvent et
     * qu'un rejeu de la fusion se reconnaisse. Un alias dont la clé est le slug gardé est effacé (un ingrédient qui existe ne s'efface pas
     * lui-même derrière un alias). Les alias déplacés prennent la source `merge` : ils ne sont plus ceux d'un lancement de la migration
     * d'adoption (`--undo` n'y touche pas) et ne ressemblent plus à un commun, ce que sait la vérification d'ING-003.
     */
    public function mergeInto(string $userId, string $absorbedSlug, string $keptSlug, \DateTimeImmutable $now): void;
}
