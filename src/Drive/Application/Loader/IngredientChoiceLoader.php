<?php

namespace Repas\Drive\Application\Loader;

/**
 * Lecture seule des choix de produits vus PAR INGRÉDIENT, pour les rapports d'un autre contexte dont les ingrédients changent de slug ou
 * fusionnent (rapport de l'amorçage, aperçu de la fusion). Des valeurs simples : l'Open Host Service qui les publie les met en forme.
 */
interface IngredientChoiceLoader
{
    /**
     * L'empreinte de ce que le contexte mémorise pour l'utilisateur (voir `IngredientChoiceRepository::fingerprint`), quelques requêtes quel
     * que soit le nombre de choix.
     *
     * @return array{choices: int, products: int, packSizeSum: string, contentHash: string, ingredientSlugByChoice: array<string, string>, productCountByChoice: array<string, int>}
     */
    public function fingerprint(string $userId): array;

    /**
     * Les slugs des ingrédients que les choix de cet utilisateur désignent (toutes enseignes, tous magasins), sans doublon, triés. Une requête.
     *
     * @return list<string>
     */
    public function referencedSlugs(string $userId): array;

    /**
     * Les slugs que les choix de CHAQUE utilisateur désignent : `[identifiant d'utilisateur => slugs triés, sans doublon]`, en une requête.
     *
     * @return array<string, list<string>>
     */
    public function referencedSlugsOfEveryUser(): array;

    /**
     * Ce que la fusion de `$absorbedSlug` dans `$keptSlug` ferait des choix de l'utilisateur, sans rien écrire. Quelques requêtes, quel que
     * soit le nombre de choix.
     *
     * @return array{moved: int, dropped: int, products: int} les choix qui passent sur le gardé, ceux supprimés, les lignes de produits supprimées avec eux
     */
    public function mergeImpact(string $userId, string $absorbedSlug, string $keptSlug): array;
}
