<?php

namespace Repas\Tests\Drive\OutputAdapter;


use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Repas\Drive\Application\Query\GetDriveSettings\DriveStoreDTO;
use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\Store;
use Repas\Drive\Domain\Service\PackCalculator;
use Repas\Drive\Infrastructure\Loader\ChoicePostgreSQLLoader;
use Repas\Drive\Infrastructure\Loader\DrivePlanPostgreSQLLoader;
use Repas\Drive\Infrastructure\Loader\DriveSettingsPostgreSQLLoader;
use Repas\Shared\Application\Dto\ShoppingListForDrive;
use Repas\Shared\Application\Dto\ShoppingListLineForDrive;
use Repas\Tests\Helper\Builder\ChoiceBuilder;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\Builder\ProductBuilder;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryLoader\IngredientNameInMemoryLoader;
use Repas\Tests\Helper\InMemoryLoader\ShoppingListForDriveInMemoryLoader;

/**
 * Un identifiant de magasin de 36 caractères (celui qu'une enseigne à identifiant explicite donne, un UUID : DRV-720) traverse toute la
 * persistance du drive sur la VRAIE base : réglage, produit, préférence, et leurs lectures. Les trois colonnes `store_id` contiennent 40
 * caractères ; deux magasins qui ne diffèrent que par la fin de leur identifiant (au-delà du vingtième caractère) restent deux magasins.
 *
 * Les valeurs sont inventées. La clé de l'enseigne de ces lignes est celle d'une enseigne du registre : la persistance ne juge pas la
 * forme de l'identifiant (c'est `Store::create`), elle le garde tel quel.
 */
class LongStoreIdTest extends DatabaseTestCase
{
    private const string STORE = '0b5f8a2e-3c1d-4e7f-9a6b-123456789abc';
    /** Les 20 premiers caractères sont ceux de `STORE` : une colonne de 20 les aurait confondus (ou tronqués) */
    private const string SIBLING = '0b5f8a2e-3c1d-4e7f-9a6b-123456789abd';
    private const string ADDRESS = 'https://www.exemple.invalid/';

    private DriveSettingsRepository $settings;
    private ProductRepository $products;
    private ChoiceRepository $choices;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = static::getContainer()->get(DriveSettingsRepository::class);
        $this->products = static::getContainer()->get(ProductRepository::class);
        $this->choices = static::getContainer()->get(ChoiceRepository::class);
    }

    public function testTheThreeStoreIdColumnsHold40Characters(): void
    {
        // When
        $columns = $this->entityManager->getConnection()->fetchAllKeyValue(
            "SELECT table_name, character_maximum_length FROM information_schema.columns WHERE column_name = 'store_id' AND table_schema = current_schema() ORDER BY table_name",
        );

        // Then : aucune autre table du schéma ne porte `store_id`
        $this->assertSame(['drive_choice' => 40, 'drive_product' => 40, 'drive_settings' => 40], $columns);
        $this->assertSame(36, strlen(self::STORE));
        $this->assertSame(substr(self::STORE, 0, 35), substr(self::SIBLING, 0, 35));
    }

    public function testASettingWithAnIdOf36CharactersIsReadBackAndReadByTheLoader(): void
    {
        // Given
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->withStoreAsRead(self::STORE, 'Ville-Test', self::ADDRESS)->build());
        $this->entityManager->clear();

        // When
        $found = $this->settings->findOneByUser('user-1', Retailer::LECLERC);
        $dto = new DriveSettingsPostgreSQLLoader($this->entityManager, new ControlledDriveFeature(true))->get('user-1', Retailer::LECLERC);

        // Then : la valeur n'est ni tronquée ni changée
        $this->assertSame([self::STORE, 'Ville-Test', self::ADDRESS], [$found->getStore()->id, $found->getStore()->label, $found->getStore()->baseUrl]);
        $this->assertEquals(new DriveStoreDTO(self::STORE, 'Ville-Test', self::ADDRESS), $dto->store);
        $this->assertSame(self::STORE, $dto->retailers[0]->store->id);
    }

    public function testChangingTheStoreToAnIdOf36CharactersReplacesThePreviousOneInTheSameRow(): void
    {
        // Given
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->entityManager->clear();

        // When
        $settings = $this->settings->findOneByUser('user-1', Retailer::LECLERC);
        $this->settings->save($settings->changeStore(Store::load(['id' => self::STORE, 'label' => null, 'base_url' => self::ADDRESS]), new DateTimeImmutable('2026-10-30 09:00:00')));
        $this->entityManager->clear();

        // Then
        $this->assertSame(self::STORE, $this->settings->findOneByUser('user-1', Retailer::LECLERC)->getStore()->id);
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_settings'));
    }

    public function testAProductOfAStoreWithAnIdOf36CharactersKeepsItsKeyAndStaysApartFromItsSibling(): void
    {
        // Given : le même produit chez deux magasins dont les identifiants ne diffèrent que par le dernier caractère
        $this->products->saveAll([
            new ProductBuilder()->withStoreId(self::STORE)->withRetailerProductId('1')->withLabel('Farine du premier magasin')->build(),
            new ProductBuilder()->withStoreId(self::SIBLING)->withRetailerProductId('1')->withLabel('Farine du second magasin')->build(),
        ]);
        $this->entityManager->clear();

        // When
        $found = $this->products->findByKeys([['retailer' => Retailer::LECLERC, 'storeId' => self::STORE, 'retailerProductId' => '1']]);

        // Then : une seule fiche, celle du premier magasin ; la clé d'unicité les distingue
        $this->assertCount(1, $found);
        $this->assertSame([self::STORE, 'Farine du premier magasin'], [$found[0]->getStoreId(), $found[0]->getLabel()]);
        $this->assertSame(2, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_product'));

        // When : le même produit est enregistré une seconde fois
        $this->products->save(new ProductBuilder()->withStoreId(self::STORE)->withRetailerProductId('1')->withLabel('Farine du premier magasin')->withPrice(149)->build());

        // Then : la même ligne est complétée, pas de doublon
        $this->assertSame(2, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_product'));
        $this->assertSame(
            ['uniq_drive_product_key'],
            $this->entityManager->getConnection()->fetchFirstColumn("SELECT indexname FROM pg_indexes WHERE tablename = 'drive_product' AND indexname = 'uniq_drive_product_key'"),
        );
    }

    public function testAChoiceOfAStoreWithAnIdOf36CharactersIsReadBackAndTheUniquenessKeepsTheWholeId(): void
    {
        // Given
        $this->choices->save(new ChoiceBuilder()->withUserId('user-1')->withStoreId(self::STORE)->withIngredientSlug('farine')->withAccepted(['p-a'])->build());
        $this->choices->save(new ChoiceBuilder()->withUserId('user-1')->withStoreId(self::SIBLING)->withIngredientSlug('farine')->withAccepted(['p-b'])->build());
        $this->entityManager->clear();

        // When
        $found = $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'farine');

        // Then : deux préférences pour le même ingrédient, une par magasin
        $this->assertSame(['p-a'], $found->getAcceptedProductIds());
        $this->assertSame(['p-b'], $this->choices->findOne('user-1', Retailer::LECLERC, self::SIBLING, 'farine')->getAcceptedProductIds());
        $this->assertSame(1, $this->choices->countByUserAndStore('user-1', Retailer::LECLERC, self::STORE));
        $this->assertSame(
            ['uniq_drive_choice_user_store_ingredient'],
            $this->entityManager->getConnection()->fetchFirstColumn("SELECT indexname FROM pg_indexes WHERE tablename = 'drive_choice' AND indexname = 'uniq_drive_choice_user_store_ingredient'"),
        );

        // When / Then : une troisième préférence pour le même couple (compte, magasin, ingrédient) heurte la clé d'unicité
        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO drive_choice (id, user_id, retailer, store_id, ingredient_slug, kind, updated_at) VALUES ('doublon', 'user-1', 'leclerc', ?, 'farine', 'PRODUCTS', '2026-10-30 09:00:00')",
            [self::STORE],
        );
    }

    public function testTheChoicesAndThePlanOfAStoreWithAnIdOf36CharactersListTheirProducts(): void
    {
        // Given : un réglage, deux produits, une préférence, une liste envoyée
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->withStoreAsRead(self::STORE, null, self::ADDRESS)->build());
        $this->products->saveAll([
            new ProductBuilder()->withStoreId(self::STORE)->withRetailerProductId('1')->withLabel('Farine T55 1 kg')->build(),
            new ProductBuilder()->withStoreId(self::SIBLING)->withRetailerProductId('2')->withLabel('Farine d\'un autre magasin')->build(),
        ]);
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withStoreId(self::STORE)->withIngredientSlug('farine')->withAccepted([Product::idFor(Retailer::LECLERC, self::STORE, '1')])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withStoreId(self::SIBLING)->withIngredientSlug('sel')->haveAtHome()->build(),
        ]);

        // When
        $choices = new ChoicePostgreSQLLoader($this->entityManager, new IngredientNameInMemoryLoader(['farine' => 'Farine']))->list('user-1', Retailer::LECLERC);
        $plan = new DrivePlanPostgreSQLLoader(
            $this->entityManager,
            new ShoppingListForDriveInMemoryLoader(new ShoppingListForDrive('list-1', 'user-1', 'SENT', 7, [new ShoppingListLineForDrive('farine', 'Farine', 300, 'gramme', 'gramme', 'g', 'WEIGHT', 1.0)])),
            new PackCalculator(),
            new FrozenClock(new DateTimeImmutable('2026-10-30T10:00:00+00:00')),
        )->get('user-1', 'list-1', Retailer::LECLERC);

        // Then : les préférences et les produits du magasin courant seulement
        $this->assertSame(['farine'], array_map(static fn($choice): string => $choice->ingredientSlug, $choices));
        $this->assertSame(['1'], array_map(static fn($accepted): string => $accepted->product->retailerProductId, $choices[0]->accepted));
        $this->assertSame(self::STORE, $plan->store->id);
        $this->assertSame('KNOWN', $plan->lines[0]->status);
        $this->assertSame(['1'], array_map(static fn($product): string => $product->retailerProductId, $plan->lines[0]->products));
    }
}
