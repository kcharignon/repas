<?php

namespace Repas\Shared\Application\Interface;


use Repas\Shared\Application\Dto\DriveChoiceFingerprint;

/**
 * Port qui laisse `Repas` repointer et vérifier les ingrédients que le contexte `Drive` mémorise (`drive_choice.ingredient_slug`)
 * quand ses ingrédients changent de slug : la migration d'adoption des communs (ING-003), et son retour arrière. Défini ici,
 * implémenté par `Drive` (ADR 0013 règle 8 : `Repas` n'importe pas `Drive`, et `Drive` reste maître de ses tables, de sa clé unique
 * `(utilisateur, enseigne, magasin, ingrédient)` et de ses produits mémorisés).
 *
 * Il ne s'appelle volontairement pas `…Loader` (un `CommandHandler` en a besoin pendant une écriture, Deptrac
 * `no-loader-in-commandhandler`), et aucune enseigne n'y est nommée : toutes les enseignes et tous les magasins d'un utilisateur sont
 * traités ensemble. Il ne touche jamais aux produits (`drive_product`, `drive_choice_product`) : ils sont rattachés à l'identifiant du
 * choix, pas au slug.
 */
interface DriveIngredientReferences
{
    /**
     * Lecture seule, quelques requêtes quel que soit le nombre de choix.
     */
    public function fingerprint(string $userId): DriveChoiceFingerprint;

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
}
