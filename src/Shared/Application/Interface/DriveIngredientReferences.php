<?php

namespace Repas\Shared\Application\Interface;


use Repas\Shared\Application\Dto\DriveChoiceFingerprint;
use Repas\Shared\Application\Dto\DriveMergeImpact;

/**
 * Port qui laisse `Repas` repointer et vérifier les ingrédients que le contexte `Drive` mémorise (`drive_choice.ingredient_slug`)
 * quand ses ingrédients changent de slug : la migration d'adoption des communs (ING-003), et son retour arrière. Défini ici,
 * implémenté par `Drive` (ADR 0013 règle 8 : `Repas` n'importe pas `Drive`, et `Drive` reste maître de ses tables, de sa clé unique
 * `(utilisateur, enseigne, magasin, ingrédient)` et de ses produits mémorisés).
 *
 * Il ne s'appelle volontairement pas `…Loader` (un `CommandHandler` en a besoin pendant une écriture, Deptrac
 * `no-loader-in-commandhandler`), et aucune enseigne n'y est nommée : toutes les enseignes et tous les magasins d'un utilisateur sont
 * traités ensemble. Le repointage ne touche jamais aux produits (`drive_product`, `drive_choice_product`) : ils sont rattachés à
 * l'identifiant du choix, pas au slug. La seule écriture qui en supprime est la fusion de deux ingrédients (ING-005, `merge()`), pour le
 * choix de l'ingrédient absorbé quand l'ingrédient gardé a le sien : les produits des faits publics (`drive_product`) ne sont jamais
 * supprimés.
 */
interface DriveIngredientReferences
{
    /**
     * Lecture seule, quelques requêtes quel que soit le nombre de choix.
     */
    public function fingerprint(string $userId): DriveChoiceFingerprint;

    /**
     * Les slugs des ingrédients que les choix de cet utilisateur désignent (toutes enseignes, tous magasins), sans doublon, triés. Lecture
     * seule, une requête. L'amorçage d'un compte (ING-008) s'en sert pour copier aussi, en plus des ingrédients « de base », ceux du
     * catalogue que ses choix référencent : le contexte `Repas` ne lit pas les tables du drive.
     *
     * @return list<string>
     */
    public function referencedSlugs(string $userId): array;

    /**
     * Les slugs que les choix de CHAQUE utilisateur désignent : `[identifiant d'utilisateur => slugs triés, sans doublon]`, en une requête. Lecture
     * seule. Sert à la requête de santé de l'amorçage (ING-008), qui vérifie qu'aucun choix ne désigne l'ingrédient d'un autre compte.
     *
     * @return array<string, list<string>>
     */
    public function referencedSlugsOfEveryUser(): array;

    /**
     * Fait passer les choix de l'utilisateur d'un slug d'ingrédient à un autre, pour TOUTES ses enseignes et TOUS ses magasins, en une
     * requête : seul `ingredient_slug` change (ni l'identifiant du choix, ni `updated_at`, ni la recherche mémorisée, ni les produits).
     * Un choix dont le slug n'est pas dans la table, ou dont le slug cible est déjà pris par un autre choix de la même enseigne et du
     * même magasin (la clé unique), n'est pas touché : l'appelant le voit à l'empreinte et au décompte rendu.
     *
     * @param array<string, string> $slugMap nouveau slug par ancien slug
     *
     * @return int le nombre de choix repointés
     */
    public function repoint(string $userId, array $slugMap): int;

    /**
     * Ce que `merge()` ferait, sans rien écrire (l'aperçu de la fusion de deux ingrédients, ING-005). Quelques requêtes, quel que soit le
     * nombre de choix.
     */
    public function mergeImpact(string $userId, string $absorbedSlug, string $keptSlug): DriveMergeImpact;

    /**
     * La fusion de l'ingrédient `$absorbedSlug` dans `$keptSlug` pour cet utilisateur (ING-005), pour TOUTES ses enseignes et TOUS ses
     * magasins, en une requête : dans un magasin où le gardé a un choix, le choix de l'absorbé est supprimé avec ses produits mémorisés ;
     * ailleurs il passe sur le gardé, avec ses produits, ses contenances et sa recherche mémorisée, sans changer d'identifiant ni de
     * date de mise à jour. Une contenance dont l'unité n'est pas celle de la ligne reste en base mais n'est jamais utilisée par le plan.
     * Les choix d'un autre utilisateur ne sont jamais touchés. Rend ce qui a été fait.
     */
    public function merge(string $userId, string $absorbedSlug, string $keptSlug): DriveMergeImpact;
}
