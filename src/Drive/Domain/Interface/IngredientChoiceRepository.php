<?php

namespace Repas\Drive\Domain\Interface;

use Repas\Drive\Domain\Model\IngredientChoiceFingerprint;
use Repas\Drive\Domain\Model\IngredientChoiceMergeImpact;

/**
 * Les choix de produits d'un utilisateur vus PAR INGRÉDIENT (`drive_choice.ingredient_slug`), pour un autre contexte dont les ingrédients
 * changent de slug (amorçage d'un compte, migration d'adoption) ou se fondent l'un dans l'autre (fusion, ING-005). Toutes les enseignes et
 * tous les magasins de l'utilisateur sont traités ensemble ; aucune enseigne n'est nommée. Les écritures ne touchent jamais aux fiches de
 * produits (`drive_product`, des faits publics) ni aux lignes de produits d'un choix gardé ; la seule suppression est celle du choix de
 * l'ingrédient absorbé quand le gardé a le sien dans le même magasin, avec ses lignes de produits.
 *
 * Les lectures de ce port servent à l'écriture en cours (l'empreinte d'avant et d'après, les slugs à copier) ; la lecture seule d'un
 * rapport passe par `IngredientChoiceLoader`.
 */
interface IngredientChoiceRepository
{
    /**
     * Lecture seule, quelques requêtes quel que soit le nombre de choix.
     */
    public function fingerprint(string $userId): IngredientChoiceFingerprint;

    /**
     * Les slugs des ingrédients que les choix de cet utilisateur désignent (toutes enseignes, tous magasins), sans doublon, triés. Lecture
     * seule, une requête.
     *
     * @return list<string>
     */
    public function referencedSlugs(string $userId): array;

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
     * La fusion de l'ingrédient `$absorbedSlug` dans `$keptSlug` pour cet utilisateur, pour TOUTES ses enseignes et TOUS ses magasins, en une
     * requête : dans un magasin où le gardé a un choix, le choix de l'absorbé est supprimé avec ses produits mémorisés ; ailleurs il passe sur
     * le gardé, avec ses produits, ses contenances et sa recherche mémorisée, sans changer d'identifiant ni de date de mise à jour. Une
     * contenance dont l'unité n'est pas celle de la ligne reste en base mais n'est jamais utilisée par le plan. Les choix d'un autre
     * utilisateur ne sont jamais touchés. Rend ce qui a été fait.
     */
    public function merge(string $userId, string $absorbedSlug, string $keptSlug): IngredientChoiceMergeImpact;
}
