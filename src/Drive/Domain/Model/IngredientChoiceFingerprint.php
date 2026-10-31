<?php

namespace Repas\Drive\Domain\Model;


/**
 * Empreinte de ce que le contexte `Drive` mémorise pour un utilisateur : ses choix de produits, vus sans le slug d'ingrédient qu'ils
 * portent. Elle sert à prouver qu'un repointage d'ingrédients (amorçage d'un compte, migration d'adoption) n'a rien perdu : mêmes choix,
 * mêmes produits, mêmes contenances, mêmes recherches mémorisées, et chaque choix sur l'ingrédient attendu.
 *
 * Aucun nom, aucun e-mail : des identifiants de choix (jamais affichés), des nombres et une somme de contrôle.
 */
final readonly class IngredientChoiceFingerprint
{
    /**
     * @param int $choices nombre de choix de l'utilisateur, toutes enseignes et tous magasins
     * @param int $products nombre de lignes de produits rattachées (acceptés et refusés)
     * @param string $packSizeSum somme des contenances mémorisées (`pack_size_value`), en texte décimal exact
     * @param string $contentHash somme de contrôle de tout ce qui n'est PAS le slug : enseigne, magasin, nature, recherche mémorisée,
     *        date de mise à jour, et pour chaque produit son identifiant, son verdict, son rang, sa contenance (valeur et unité) et les valeurs
     *        que le compte a lui-même observées (libellé, prix et date du prix)
     * @param array<string, string> $ingredientSlugByChoice le slug d'ingrédient de chaque choix, par identifiant de choix
     * @param array<string, int> $productCountByChoice le nombre de lignes de produits de chaque choix, par identifiant de choix
     */
    public function __construct(
        public int $choices,
        public int $products,
        public string $packSizeSum,
        public string $contentHash,
        public array $ingredientSlugByChoice,
        public array $productCountByChoice,
    ) {
    }
}
