<?php

namespace Repas\Tests\Drive\OutputAdapter;


use DateTimeImmutable;
use Repas\Drive\Application\Loader\ChoiceLoader;
use Repas\Drive\Application\Query\ListChoices\AcceptedProductDTO;
use Repas\Drive\Application\Query\ListChoices\ChoiceDTO;
use Repas\Drive\Application\Query\ListChoices\ChoiceProductDTO;
use Repas\Drive\Application\Query\ListChoices\PackSizeDTO;
use Repas\Drive\Application\Query\ListChoices\ProductContentDTO;
use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Drive\Domain\Model\ContentUnit;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\ProductContent;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Infrastructure\Loader\ChoicePostgreSQLLoader;
use Repas\Tests\Helper\Builder\ChoiceBuilder;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\Builder\ProductBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\InMemoryLoader\IngredientNameInMemoryLoader;
use Repas\Tests\Helper\SqlBudget\QueryCounter;

class ChoicePostgreSQLLoaderTest extends DatabaseTestCase
{
    private ProductRepository $products;
    private ChoiceRepository $choices;
    private DriveSettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->products = static::getContainer()->get(ProductRepository::class);
        $this->choices = static::getContainer()->get(ChoiceRepository::class);
        $this->settings = static::getContainer()->get(DriveSettingsRepository::class);
    }

    public function testListsTheChoicesOfTheCurrentStoreWithRankedAndRejectedProducts(): void
    {
        // Given
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->products->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->withEan('3017620422003')->withLabel('Farine T55 Marque Repère 1 kg')->withPrice(149)->build(),
            new ProductBuilder()->withRetailerProductId('2')->withLabel('Farine T45 1 kg')->withContent(new ProductContent(0.5, ContentUnit::KG))->build(),
            new ProductBuilder()->withRetailerProductId('3')->withLabel('Farine complète bio 1 kg')->withContent(null)->build(),
        ]);
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted([$this->id('2'), $this->id('1')])->withRejected([$this->id('3')])
                ->withSearchQuery('farine de blé t55')->withUpdatedAt(new DateTimeImmutable('2026-10-18 10:00:00'))->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('sel')->haveAtHome()->withUpdatedAt(new DateTimeImmutable('2026-10-18 11:00:00'))->build(),
        ]);

        // When
        $result = $this->loader(['farine' => 'Farine'])->list('user-1', Retailer::LECLERC);

        // Then : triés par slug, produits acceptés par rang, refusés ; le nom manquant devient le slug
        $this->assertEquals([
            new ChoiceDTO(
                'farine',
                'Farine',
                false,
                [
                    new AcceptedProductDTO(1, new ChoiceProductDTO($this->id('2'), '2', null, 'Farine T45 1 kg', new ProductContentDTO(0.5, 'kg'), null)),
                    new AcceptedProductDTO(2, new ChoiceProductDTO($this->id('1'), '1', '3017620422003', 'Farine T55 Marque Repère 1 kg', new ProductContentDTO(1000, 'g'), null)),
                ],
                [new ChoiceProductDTO($this->id('3'), '3', null, 'Farine complète bio 1 kg', null, null)],
                new DateTimeImmutable('2026-10-18 10:00:00')->format(DATE_ATOM),
                'farine de blé t55',
            ),
            new ChoiceDTO('sel', 'sel', true, [], [], new DateTimeImmutable('2026-10-18 11:00:00')->format(DATE_ATOM), null),
        ], $result);
    }

    public function testEachAcceptedProductExposesItsOwnPackSizeOrNull(): void
    {
        // Given : le préféré est compté en 20 tranches, le produit de secours n'a rien, le refusé n'a jamais rien
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->products->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->withLabel('Fromage Cheddar Burger 20 Tranches - 340g')->build(),
            new ProductBuilder()->withRetailerProductId('2')->withLabel('Cheddar tranches 10')->build(),
            new ProductBuilder()->withRetailerProductId('3')->withLabel('Cheddar en bloc')->build(),
        ]);
        $this->choices->save(new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('cheddar')
            ->withAccepted([$this->id('1'), $this->id('2')])->withRejected([$this->id('3')])->withPackSize($this->id('1'), 20, 'tranche')->build());

        // When
        $result = $this->loader()->list('user-1', Retailer::LECLERC);

        // Then : la clé est toujours là, null quand rien n'est mémorisé
        $this->assertSame(
            [[1, ['value' => 20.0, 'unitSlug' => 'tranche']], [2, null]],
            array_map(static fn(AcceptedProductDTO $accepted): array => [$accepted->rank, $accepted->product->packSize === null ? null : ['value' => $accepted->product->packSize->value, 'unitSlug' => $accepted->product->packSize->unitSlug]], $result[0]->accepted),
        );
        $this->assertEquals(new PackSizeDTO(20, 'tranche'), $result[0]->accepted[0]->product->packSize);
        $this->assertNull($result[0]->rejected[0]->packSize);
    }

    public function testThePackSizeOfAnotherUserOrAnOldStoreIsNeverListed(): void
    {
        // Given : la même ligne de produit chez un autre utilisateur et dans un ancien magasin de l'utilisateur
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->products->save(new ProductBuilder()->withRetailerProductId('1')->build());
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('cheddar')->withAccepted([$this->id('1')])->build(),
            new ChoiceBuilder()->withUserId('user-2')->withIngredientSlug('cheddar')->withAccepted([$this->id('1')])->withPackSize($this->id('1'), 30, 'tranche')->build(),
            new ChoiceBuilder()->withUserId('user-1')->withStoreId('999999')->withIngredientSlug('emmental')->withAccepted([$this->id('1')])->withPackSize($this->id('1'), 40, 'tranche')->build(),
        ]);

        // When
        $result = $this->loader()->list('user-1', Retailer::LECLERC);

        // Then
        $this->assertSame(['cheddar'], array_map(static fn(ChoiceDTO $choice): string => $choice->ingredientSlug, $result));
        $this->assertNull($result[0]->accepted[0]->product->packSize);
    }

    public function testEachChoiceExposesItsOwnRememberedSearchOrNull(): void
    {
        // Given
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('cereale')->withAccepted(['p-1'])->withSearchQuery('céréales coussins chocolat')->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted(['p-2'])->build(),
        ]);

        // When
        $result = $this->loader()->list('user-1', Retailer::LECLERC);

        // Then : la clé est toujours là, null quand rien n'est mémorisé
        $this->assertSame(['cereale' => 'céréales coussins chocolat', 'farine' => null], array_column(array_map(static fn(ChoiceDTO $dto): array => [$dto->ingredientSlug, $dto->searchQuery], $result), 1, 0));
    }

    public function testTheSearchOfAnotherRetailerAnotherUserOrAnOldStoreIsNeverListed(): void
    {
        // Given : le même ingrédient chez une autre enseigne (écrit en SQL : le code ne connaît qu'une enseigne), chez un autre
        // utilisateur, et dans l'ancien magasin du même utilisateur
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('sel')->withAccepted(['p-1'])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withStoreId('654321')->withIngredientSlug('sel')->withAccepted(['p-1'])->withSearchQuery('recherche de l ancien magasin')->build(),
            new ChoiceBuilder()->withUserId('user-2')->withIngredientSlug('sel')->withAccepted(['p-1'])->withSearchQuery('recherche de user-2')->build(),
        ]);
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO drive_choice (id, user_id, retailer, store_id, ingredient_slug, kind, search_query, updated_at) VALUES ('other-retailer', 'user-1', 'autre-enseigne', '123456', 'sel', 'PRODUCTS', 'recherche pour une autre enseigne', '2026-10-18 10:00:00')",
        );

        // When
        $result = $this->loader()->list('user-1', Retailer::LECLERC);

        // Then : une seule ligne, sans recherche : rien n'est repris d'ailleurs
        $this->assertCount(1, $result);
        $this->assertNull($result[0]->searchQuery);
    }

    public function testAHaveAtHomeRowNeverExposesASearchLeftByAnOlderVersion(): void
    {
        // Given : une version plus ancienne du code a repassé la ligne en « j'en ai toujours » sans effacer la colonne
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->choices->save(new ChoiceBuilder()->withId('choice-1')->withUserId('user-1')->withIngredientSlug('sel')->withAccepted(['p-1'])->withSearchQuery('gros sel')->build());
        $this->entityManager->getConnection()->executeStatement("UPDATE drive_choice SET kind = 'HAVE_AT_HOME' WHERE id = 'choice-1'");

        // When
        $result = $this->loader()->list('user-1', Retailer::LECLERC);

        // Then
        $this->assertTrue($result[0]->haveAtHome);
        $this->assertNull($result[0]->searchQuery);
    }

    public function testOnlyTheChoicesOfTheCurrentStoreAndTheUserAreListed(): void
    {
        // Given
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-2')->withStore('654321', null, 'https://autre.leclercdrive.fr/magasin-654321-654321')->build());
        $this->products->save(new ProductBuilder()->withRetailerProductId('1')->build());
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted([$this->id('1')])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withStoreId('654321')->withIngredientSlug('sel')->withAccepted([$this->id('1')])->build(),
            new ChoiceBuilder()->withUserId('user-2')->withIngredientSlug('sucre')->withAccepted([$this->id('1')])->build(),
            new ChoiceBuilder()->withUserId('user-2')->withStoreId('654321')->withIngredientSlug('lait')->withAccepted([$this->id('1')])->build(),
        ]);

        // Then : l'ancien magasin de user-1 et le magasin de user-2 n'apparaissent pas chez l'autre
        $this->assertSame(['farine'], $this->slugs('user-1'));
        $this->assertSame(['lait'], $this->slugs('user-2'));
        $this->assertSame([], $this->slugs('user-3'));
    }

    public function testWithoutSettingsTheListIsEmpty(): void
    {
        // Given : des préférences, mais aucun magasin enregistré
        $this->choices->save(new ChoiceBuilder()->withUserId('user-1')->withAccepted(['p'])->build());

        // Then
        $this->assertSame([], $this->slugs('user-1'));
    }

    public function testALineWhoseProductIsUnknownIsLeftOut(): void
    {
        // Given
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->choices->save(new ChoiceBuilder()->withUserId('user-1')->withAccepted(['produit-inconnu'])->withRejected(['autre-inconnu'])->build());

        // When
        $result = $this->loader()->list('user-1', Retailer::LECLERC);

        // Then
        $this->assertCount(1, $result);
        $this->assertSame([[], []], [$result[0]->accepted, $result[0]->rejected]);
    }

    public function testReadingCostsAFixedNumberOfQueries(): void
    {
        // Given : 30 ingrédients avec leurs produits
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $products = $choices = [];
        foreach (range(1, 30) as $i) {
            $products[] = new ProductBuilder()->withRetailerProductId((string) $i)->build();
            $products[] = new ProductBuilder()->withRetailerProductId((string) ($i + 100))->build();
            $choices[] = new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug("ingredient-$i")->withAccepted([$this->id((string) $i)])->withRejected([$this->id((string) ($i + 100))])->build();
        }
        $this->products->saveAll($products);
        $this->choices->saveAll($choices);
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When : avec le vrai port des noms d'ingrédients
        $result = static::getContainer()->get(ChoiceLoader::class)->list('user-1', Retailer::LECLERC);

        // Then : les préférences avec leurs produits, puis les noms
        $this->assertCount(30, $result);
        $this->assertSame(['SELECT drive_choice' => 1, 'SELECT ingredient' => 1], $counter->byTable());
    }

    /**
     * @param array<string, string> $names
     */
    private function loader(array $names = []): ChoicePostgreSQLLoader
    {
        return new ChoicePostgreSQLLoader($this->entityManager, new IngredientNameInMemoryLoader($names));
    }

    /**
     * @return list<string>
     */
    private function slugs(string $userId): array
    {
        return array_map(static fn(ChoiceDTO $choice): string => $choice->ingredientSlug, $this->loader()->list($userId, Retailer::LECLERC));
    }

    private function id(string $retailerProductId): string
    {
        return Product::idFor(Retailer::LECLERC, '123456', $retailerProductId);
    }
}
