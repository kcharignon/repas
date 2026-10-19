<?php

namespace Repas\Drive\Infrastructure\Repository;


use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Drive\Domain\Model\ContentUnit;
use Repas\Drive\Domain\Model\PricePerMeasure;
use Repas\Drive\Domain\Model\PriceUnit;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\ProductContent;
use Repas\Drive\Domain\Model\ProductPrice;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Infrastructure\Entity\Product as ProductEntity;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;

/**
 * Lectures et écritures en SQL direct : un lot de produits coûte une requête, quelle que soit sa taille (un `persist()` par
 * produit en coûterait un chacun). Aucun produit n'est donc dans l'identity map de Doctrine.
 */
readonly class ProductPostgreSQLRepository extends PostgreSQLRepository implements ProductRepository
{
    private const string DATE_FORMAT = 'Y-m-d H:i:s';
    private const string COLUMNS = 'id, retailer, store_id, retailer_product_id, ean, label, content_value, content_unit, price_cents, price_per_measure_cents, price_per_measure_unit, price_seen_at, last_seen_at, rayon_id, family_id';

    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, ProductEntity::class);
    }

    public function findByKeys(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $params = [];
        foreach ($keys as $key) {
            array_push($params, $key['retailer']->value, $key['storeId'], $key['retailerProductId']);
        }
        $tuples = implode(', ', array_fill(0, count($keys), '(?, ?, ?)'));

        return $this->fetch(sprintf('WHERE (retailer, store_id, retailer_product_id) IN (%s)', $tuples), $params);
    }

    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->fetch('WHERE id IN (?)', [$ids], [ArrayParameterType::STRING]);
    }

    public function saveAll(array $products): void
    {
        if ($products === []) {
            return;
        }

        $params = [];
        foreach ($products as $product) {
            $content = $product->getContent();
            $price = $product->getPrice();
            array_push(
                $params,
                $product->getId(),
                $product->getRetailer()->value,
                $product->getStoreId(),
                $product->getRetailerProductId(),
                $product->getEan(),
                $product->getLabel(),
                $content?->value,
                $content?->unit->value,
                $price?->cents,
                $price?->perMeasure?->cents,
                $price?->perMeasure?->unit->value,
                $product->getPriceSeenAt()?->format(self::DATE_FORMAT),
                $product->getLastSeenAt()->format(self::DATE_FORMAT),
                $product->getRayonId(),
                $product->getFamilyId(),
            );
        }
        $tuples = implode(', ', array_fill(0, count($products), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'));

        // La clé naturelle fait foi : un produit qu'une autre requête vient de créer est mis à jour, jamais dupliqué (et son
        // identifiant, déduit de la même clé, est le même). Premier écrivain gagnant jusque dans la base (DRV-613) : si deux
        // requêtes créent la même fiche en même temps, la seconde ne remplace ni le libellé, ni le code-barres, ni le contenu
        // déjà écrits (le modèle, lui, ne fait que les compléter ; le prix, que son propriétaire rafraîchit, est écrit tel quel). Le
        // rayon et la famille de l'enseigne (DRV-642) suivent la même règle : le premier écrit reste, `NULL` n'efface rien.
        $this->connection()->executeStatement(
            sprintf(
                'INSERT INTO drive_product (%s) VALUES %s ON CONFLICT (retailer, store_id, retailer_product_id) DO UPDATE SET '
                . 'ean = COALESCE(drive_product.ean, EXCLUDED.ean), '
                . 'content_unit = CASE WHEN drive_product.content_value IS NULL THEN EXCLUDED.content_unit ELSE drive_product.content_unit END, '
                . 'content_value = COALESCE(drive_product.content_value, EXCLUDED.content_value), '
                . 'price_cents = EXCLUDED.price_cents, price_per_measure_cents = EXCLUDED.price_per_measure_cents, '
                . 'price_per_measure_unit = EXCLUDED.price_per_measure_unit, price_seen_at = EXCLUDED.price_seen_at, '
                . 'last_seen_at = EXCLUDED.last_seen_at, '
                . 'rayon_id = COALESCE(drive_product.rayon_id, EXCLUDED.rayon_id), '
                . 'family_id = COALESCE(drive_product.family_id, EXCLUDED.family_id)',
                self::COLUMNS,
                $tuples,
            ),
            $params,
        );
    }

    public function save(Product $product): void
    {
        $this->saveAll([$product]);
    }

    /**
     * @param list<mixed> $params
     * @param list<mixed> $types
     *
     * @return list<Product>
     */
    private function fetch(string $where, array $params, array $types = []): array
    {
        $rows = $this->connection()->fetchAllAssociative(sprintf('SELECT %s FROM drive_product %s ORDER BY id', self::COLUMNS, $where), $params, $types);

        return array_map($this->convertRowToModel(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function convertRowToModel(array $row): Product
    {
        $perMeasure = $row['price_per_measure_cents'] === null
            ? null
            : new PricePerMeasure((int) $row['price_per_measure_cents'], PriceUnit::from($row['price_per_measure_unit']));

        return Product::load([
            'id' => $row['id'],
            'retailer' => Retailer::from($row['retailer']),
            'store_id' => $row['store_id'],
            'retailer_product_id' => $row['retailer_product_id'],
            'ean' => $row['ean'],
            'label' => $row['label'],
            'content' => $row['content_value'] === null ? null : new ProductContent((float) $row['content_value'], ContentUnit::from($row['content_unit'])),
            'price' => $row['price_cents'] === null ? null : new ProductPrice((int) $row['price_cents'], $perMeasure),
            'price_seen_at' => $row['price_seen_at'] === null ? null : new DateTimeImmutable($row['price_seen_at']),
            'last_seen_at' => new DateTimeImmutable($row['last_seen_at']),
            'rayon_id' => $row['rayon_id'] === null ? null : (int) $row['rayon_id'],
            'family_id' => $row['family_id'] === null ? null : (int) $row['family_id'],
        ]);
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
