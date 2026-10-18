<?php

namespace Repas\Tests\Drive\OutputAdapter;


use DateTimeImmutable;
use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Drive\Domain\Model\ContentUnit;
use Repas\Drive\Domain\Model\PriceUnit;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\ProductContent;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Tests\Helper\Builder\ProductBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\SqlBudget\QueryCounter;

class ProductRepositoryTest extends DatabaseTestCase
{
    private ProductRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(ProductRepository::class);
    }

    public function testASavedProductIsReadBackWithAllItsValues(): void
    {
        // Given
        $seenAt = new DateTimeImmutable('2026-10-17 08:30:00');
        $product = new ProductBuilder()->withRetailerProductId('555111')->withEan('3017620422003')->withLabel('Farine T55 1 kg')
            ->withContent(new ProductContent(0.5, ContentUnit::KG))->withPrice(149, 298, PriceUnit::KG, $seenAt)
            ->withLastSeenAt(new DateTimeImmutable('2026-10-18 10:15:00'))->build();
        $this->repository->saveAll([$product]);
        $this->entityManager->clear();

        // When
        $found = $this->repository->findByKeys([['retailer' => Retailer::LECLERC, 'storeId' => '123456', 'retailerProductId' => '555111']]);

        // Then
        $this->assertCount(1, $found);
        $this->assertTrue($product->isEqual($found[0]));
        $this->assertEquals($product->toArray(), $found[0]->toArray());
        $this->assertEquals($product->getContent(), $found[0]->getContent());
        $this->assertEquals($product->getPrice(), $found[0]->getPrice());
        $this->assertEquals($seenAt, $found[0]->getPriceSeenAt());
    }

    public function testAProductWithoutEanContentNorPriceIsReadBackWithNulls(): void
    {
        // Given
        $this->repository->save(new ProductBuilder()->withContent(null)->build());

        // When
        $found = $this->repository->findByIds([Product::idFor(Retailer::LECLERC, '123456', '555111')])[0];

        // Then
        $this->assertNull($found->getEan());
        $this->assertNull($found->getContent());
        $this->assertNull($found->getPrice());
        $this->assertNull($found->getPriceSeenAt());
    }

    public function testFindByKeysReturnsTheKnownProductsOnlyAndTheStoreIsPartOfTheKey(): void
    {
        // Given
        $this->repository->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->build(),
            new ProductBuilder()->withRetailerProductId('2')->build(),
            new ProductBuilder()->withStoreId('654321')->withRetailerProductId('1')->withLabel('Même numéro, autre magasin')->build(),
        ]);

        // When
        $found = $this->repository->findByKeys([
            ['retailer' => Retailer::LECLERC, 'storeId' => '123456', 'retailerProductId' => '1'],
            ['retailer' => Retailer::LECLERC, 'storeId' => '123456', 'retailerProductId' => '404'],
            ['retailer' => Retailer::LECLERC, 'storeId' => '654321', 'retailerProductId' => '2'],
        ]);

        // Then
        $this->assertCount(1, $found);
        $this->assertSame(['123456', '1'], [$found[0]->getStoreId(), $found[0]->getRetailerProductId()]);
        $this->assertSame([], $this->repository->findByKeys([]));
    }

    public function testFindByIds(): void
    {
        // Given
        $this->repository->saveAll([new ProductBuilder()->withRetailerProductId('1')->build(), new ProductBuilder()->withRetailerProductId('2')->build()]);

        // When
        $found = $this->repository->findByIds([Product::idFor(Retailer::LECLERC, '123456', '2'), 'inconnu']);

        // Then
        $this->assertSame(['2'], array_map(static fn(Product $product): string => $product->getRetailerProductId(), $found));
        $this->assertSame([], $this->repository->findByIds([]));
    }

    public function testSavingAKnownKeyUpdatesTheSameRow(): void
    {
        // Given
        $this->repository->save(new ProductBuilder()->withLabel('Avant')->withPrice(100)->build());
        $product = $this->repository->findByIds([Product::idFor(Retailer::LECLERC, '123456', '555111')])[0];

        // When
        $this->repository->save($product->see(null, 'Après', new ProductContent(2, ContentUnit::L), null, new DateTimeImmutable('2026-10-19 09:00:00')));
        $this->entityManager->clear();

        // Then : une seule ligne, la clé naturelle fait foi
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_product'));
        $found = $this->repository->findByIds([$product->getId()])[0];
        $this->assertSame('Après', $found->getLabel());
        $this->assertEquals(new ProductContent(2, ContentUnit::L), $found->getContent());
        $this->assertSame(100, $found->getPrice()->cents);
    }

    public function testAProductAnotherRequestJustCreatedIsUpdatedNotDuplicated(): void
    {
        // Given : deux requêtes ont lu « produit inconnu » avant que l'une d'elles écrive
        $this->repository->save(new ProductBuilder()->withLabel('Écrit en premier')->build());
        $created = Product::create(Retailer::LECLERC, '123456', '555111', null, 'Écrit en second', null, null, new DateTimeImmutable('2026-10-19 09:00:00'));

        // When
        $this->repository->save($created);

        // Then
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_product'));
        $this->assertSame('Écrit en second', $this->repository->findByIds([$created->getId()])[0]->getLabel());
    }

    public function testReadingAndWritingABatchCostsOneQueryEach(): void
    {
        // Given
        $products = array_map(static fn(int $i): Product => new ProductBuilder()->withRetailerProductId((string) $i)->build(), range(1, 50));
        $keys = array_map(static fn(Product $product): array => ['retailer' => Retailer::LECLERC, 'storeId' => '123456', 'retailerProductId' => $product->getRetailerProductId()], $products);
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->repository->saveAll($products);
        $this->repository->saveAll($products);
        $found = $this->repository->findByKeys($keys);

        // Then
        $this->assertCount(50, $found);
        $this->assertSame(['INSERT drive_product' => 2, 'SELECT drive_product' => 1], $counter->byTable());
    }
}
