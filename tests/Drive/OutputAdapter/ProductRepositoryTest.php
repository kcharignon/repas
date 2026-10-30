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
        $this->repository->save(new ProductBuilder()->withLabel('Avant')->withContent(null)->withPrice(100)->build());
        $product = $this->repository->findByIds([Product::idFor(Retailer::LECLERC, '123456', '555111')])[0];

        // When
        $this->repository->save($product->complete('12345678', 'Après', new ProductContent(2, ContentUnit::L), null, new DateTimeImmutable('2026-10-19 09:00:00')));
        $this->entityManager->clear();

        // Then : une seule ligne, la clé naturelle fait foi ; le libellé, lui, reste celui du premier écrivain
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_product'));
        $found = $this->repository->findByIds([$product->getId()])[0];
        $this->assertSame('Avant', $found->getLabel());
        $this->assertSame('12345678', $found->getEan());
        $this->assertEquals(new ProductContent(2, ContentUnit::L), $found->getContent());
        $this->assertSame(100, $found->getPrice()->cents);
    }

    public function testAProductAnotherRequestJustCreatedIsUpdatedNotDuplicated(): void
    {
        // Given : deux requêtes ont lu « produit inconnu » avant que l'une d'elles écrive
        $this->repository->save(new ProductBuilder()->withLabel('Écrit en premier')->withEan('30000000')->build());
        $created = Product::create(Retailer::LECLERC, '123456', '555111', '40000000', 'Écrit en second', new ProductContent(7, ContentUnit::L), null, new DateTimeImmutable('2026-10-19 09:00:00'));

        // When
        $this->repository->save($created);

        // Then : une seule ligne, et le second ne réécrit ni le libellé ni le code-barres du premier (premier écrivain gagnant)
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_product'));
        $found = $this->repository->findByIds([$created->getId()])[0];
        $this->assertSame(['Écrit en premier', '30000000'], [$found->getLabel(), $found->getEan()]);
    }

    public function testTheRayonAndTheFamilyAreReadBackAndOlderRowsHaveNone(): void
    {
        // Given : un produit qui connaît son rayon, un autre dont la fiche date d'avant (colonnes à NULL)
        $this->repository->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->withRayon(4001, 4101)->build(),
            new ProductBuilder()->withRetailerProductId('2')->build(),
        ]);
        $this->entityManager->clear();

        // When
        $found = $this->repository->findByIds([Product::idFor(Retailer::LECLERC, '123456', '1'), Product::idFor(Retailer::LECLERC, '123456', '2')]);

        // Then
        $byProduct = [];
        foreach ($found as $product) {
            $byProduct[$product->getRetailerProductId()] = [$product->getRayonId(), $product->getFamilyId()];
        }
        ksort($byProduct);
        $this->assertSame(['1' => [4001, 4101], '2' => [null, null]], $byProduct);
    }

    public function testAKnownRayonIsNotReplacedNorEmptiedByASecondWriterButAMissingOneIsFilled(): void
    {
        // Given : un rayon écrit (produit 1), une fiche sans rayon (produit 2)
        $this->repository->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->withRayon(4001, 4101)->build(),
            new ProductBuilder()->withRetailerProductId('2')->build(),
        ]);
        $rival = static fn(string $id, ?int $rayonId, ?int $familyId): Product => Product::create(Retailer::LECLERC, '123456', $id, null, 'Autre', null, null, new DateTimeImmutable('2026-10-19 09:00:00'), $rayonId, $familyId);

        // When : des écrivains concurrents qui n'avaient pas vu les fiches
        $this->repository->saveAll([$rival('1', 4999, 4999), $rival('2', 4002, 4102)]);
        $this->repository->saveAll([$rival('1', null, null), $rival('2', null, null)]);

        // Then : le rayon connu reste (même devant un autre, même devant l'absence), celui qui manquait est rempli
        $rayons = static fn(Product $product): array => [$product->getRayonId(), $product->getFamilyId()];
        $this->assertSame([4001, 4101], $rayons($this->repository->findByIds([Product::idFor(Retailer::LECLERC, '123456', '1')])[0]));
        $this->assertSame([4002, 4102], $rayons($this->repository->findByIds([Product::idFor(Retailer::LECLERC, '123456', '2')])[0]));
    }

    public function testAContentAlreadyWrittenIsNotReplacedByASecondWriterButAnEmptyOneIsFilled(): void
    {
        // Given : un contenu écrit (1 kg), une fiche sans contenu
        $this->repository->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->build(),
            new ProductBuilder()->withRetailerProductId('2')->withContent(null)->build(),
        ]);
        $rival = static fn(string $id): Product => Product::create(Retailer::LECLERC, '123456', $id, null, 'Autre', new ProductContent(10, ContentUnit::CL), null, new DateTimeImmutable('2026-10-19 09:00:00'));

        // When : un écrivain concurrent, qui n'avait pas vu les fiches, propose 10 cl pour les deux
        $this->repository->saveAll([$rival('1'), $rival('2')]);

        // Then
        $this->assertEquals(new ProductContent(1000, ContentUnit::G), $this->repository->findByIds([Product::idFor(Retailer::LECLERC, '123456', '1')])[0]->getContent());
        $this->assertEquals(new ProductContent(10, ContentUnit::CL), $this->repository->findByIds([Product::idFor(Retailer::LECLERC, '123456', '2')])[0]->getContent());
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

    public function testAnOfferIsWrittenReadBackAndKeptAbsentForAnEnseigneWithoutOffers(): void
    {
        // Given : un produit avec offre, un sans (le builder charge le modèle sans le juger : aucune enseigne du registre n'a d'offre)
        $withOffer = new ProductBuilder()->withRetailerProductId('1')->withOfferId('c3b2a1d0-5e4f-4a6b-9c8d-7e6f5a4b3c2d')->build();
        $without = new ProductBuilder()->withRetailerProductId('2')->build();

        // When
        $this->repository->saveAll([$withOffer, $without]);
        $this->entityManager->clear();

        // Then
        $found = $this->repository->findByIds([$withOffer->getId(), $without->getId()]);
        $byProduct = [];
        foreach ($found as $product) {
            $byProduct[$product->getRetailerProductId()] = $product->getOfferId();
        }
        ksort($byProduct);
        $this->assertSame(['1' => 'c3b2a1d0-5e4f-4a6b-9c8d-7e6f5a4b3c2d', '2' => null], $byProduct);
    }

    public function testASeenOfferReplacesTheStoredOneAndNothingNeverEmptiesIt(): void
    {
        // Given : deux fiches avec une offre
        $this->repository->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->withOfferId('c3b2a1d0-5e4f-4a6b-9c8d-7e6f5a4b3c2d')->build(),
            new ProductBuilder()->withRetailerProductId('2')->withOfferId('c3b2a1d0-5e4f-4a6b-9c8d-7e6f5a4b3c2d')->build(),
        ]);

        // When : l'offre du produit 1 change, l'écrivain du produit 2 n'en connaît pas
        $this->repository->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->withOfferId('d4c3b2a1-6f50-4b7c-8d9e-8f7a6b5c4d3e')->build(),
            new ProductBuilder()->withRetailerProductId('2')->build(),
        ]);

        // Then : contrairement au rayon, l'offre reçue remplace l'ancienne ; l'absence ne l'efface pas
        $offer = fn(string $id): ?string => $this->repository->findByIds([Product::idFor(Retailer::LECLERC, '123456', $id)])[0]->getOfferId();
        $this->assertSame('d4c3b2a1-6f50-4b7c-8d9e-8f7a6b5c4d3e', $offer('1'));
        $this->assertSame('c3b2a1d0-5e4f-4a6b-9c8d-7e6f5a4b3c2d', $offer('2'));
    }

    public function testTheNaturalKeyIsTheProductNotTheOffer(): void
    {
        // Given : deux écritures du même produit avec deux offres différentes
        $this->repository->saveAll([new ProductBuilder()->withRetailerProductId('1')->withOfferId('c3b2a1d0-5e4f-4a6b-9c8d-7e6f5a4b3c2d')->build()]);
        $this->repository->saveAll([new ProductBuilder()->withRetailerProductId('1')->withOfferId('d4c3b2a1-6f50-4b7c-8d9e-8f7a6b5c4d3e')->build()]);

        // Then : une seule fiche (la clé reste `(retailer, store_id, retailer_product_id)`)
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM drive_product'));
    }
}
