<?php

namespace Repas\Drive\Domain\Interface;

use Repas\Drive\Domain\Model\Choice;
use Repas\Drive\Domain\Model\Retailer;

interface ChoiceRepository
{
    /**
     * Les préférences de l'utilisateur pour ces ingrédients dans ce magasin, avec leurs produits, en une seule requête ; un
     * ingrédient sans préférence est absent du résultat.
     *
     * @param list<string> $ingredientSlugs
     *
     * @return list<Choice>
     */
    public function findByUserStoreAndSlugs(string $userId, Retailer $retailer, string $storeId, array $ingredientSlugs): array;

    public function findOne(string $userId, Retailer $retailer, string $storeId, string $ingredientSlug): ?Choice;

    /**
     * Nombre de préférences de l'utilisateur dans ce magasin (plafond `Choice::MAX_PER_USER_AND_STORE`), en une seule requête.
     */
    public function countByUserAndStore(string $userId, Retailer $retailer, string $storeId): int;

    /**
     * Enregistre ces préférences (crée ou met à jour) et réécrit toutes les lignes de leurs produits, en un nombre de requêtes
     * qui ne dépend pas du nombre de préférences.
     *
     * @param list<Choice> $choices
     */
    public function saveAll(array $choices): void;

    public function save(Choice $choice): void;

    /**
     * Supprime la préférence et les lignes de ses produits (les produits restent : ce sont des faits publics).
     */
    public function delete(Choice $choice): void;

    /**
     * Supprime les lignes de produits de toutes les préférences de l'utilisateur (suppression de son compte, ADR 0021). À
     * appeler avant `deleteByUser()` : aucune clé étrangère ne retrouverait ces lignes ensuite.
     */
    public function deleteProductsByUser(string $userId): void;

    /**
     * Supprime toutes les préférences de l'utilisateur, tous magasins (suppression de son compte, ADR 0021).
     */
    public function deleteByUser(string $userId): void;
}
