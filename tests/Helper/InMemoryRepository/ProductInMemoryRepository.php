<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Drive\Domain\Model\Product;

/**
 * @extends AbstractInMemoryRepository<Product>
 */
class ProductInMemoryRepository extends AbstractInMemoryRepository implements ProductRepository
{
    /** @var int nombre d'appels à `findByKeys()` */
    public int $findByKeysCalls = 0;

    /** @var list<int> nombre de produits reçus à chaque `saveAll()` */
    public array $saveAllSizes = [];

    protected static function getClassName(): string
    {
        return Product::class;
    }

    public function findByKeys(array $keys): array
    {
        $this->findByKeysCalls++;
        $wanted = array_map(static fn(array $key): string => Product::keyOf($key['retailer'], $key['storeId'], $key['retailerProductId']), $keys);

        return array_values(array_map(
            static fn(Product $product): Product => clone $product,
            array_filter($this->models->toArray(), static fn(Product $product): bool => in_array($product->getKey(), $wanted, true)),
        ));
    }

    public function findByIds(array $ids): array
    {
        return array_values(array_map(
            static fn(Product $product): Product => clone $product,
            array_filter($this->models->toArray(), static fn(Product $product): bool => in_array($product->getId(), $ids, true)),
        ));
    }

    public function saveAll(array $products): void
    {
        $this->saveAllSizes[] = count($products);
        foreach ($products as $product) {
            $this->models[$product->getId()] = clone $product;
        }
    }

    public function save(Product $product): void
    {
        $this->saveAll([$product]);
    }

    /**
     * @return list<Product>
     */
    public function all(): array
    {
        return array_values($this->models->toArray());
    }
}
