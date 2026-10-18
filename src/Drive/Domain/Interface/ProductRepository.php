<?php

namespace Repas\Drive\Domain\Interface;

use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\Retailer;

interface ProductRepository
{
    /**
     * Les produits connus parmi ces clés naturelles, en une seule requête ; une clé inconnue est absente du résultat.
     *
     * @param list<array{retailer: Retailer, storeId: string, retailerProductId: string}> $keys
     *
     * @return list<Product>
     */
    public function findByKeys(array $keys): array;

    /**
     * Les produits connus parmi ces identifiants Repas, en une seule requête ; un identifiant inconnu est absent du résultat.
     *
     * @param list<string> $ids
     *
     * @return list<Product>
     */
    public function findByIds(array $ids): array;

    /**
     * Crée ou met à jour ces produits (clé naturelle `(retailer, storeId, retailerProductId)`), en une seule requête quel que
     * soit leur nombre.
     *
     * @param list<Product> $products
     */
    public function saveAll(array $products): void;

    public function save(Product $product): void;
}
