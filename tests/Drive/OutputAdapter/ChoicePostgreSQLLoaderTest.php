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
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ProductBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
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

    public function testAChoiceThatOnlyKeepsASearchIsNotListedAsAProduct(): void
    {
        // Given : produit pris « cette fois seulement » (DRV-653) : une préférence sans produit accepté ni refusé, avec sa recherche ;
        // un refus seul, « j'en ai toujours » et un produit habituel sont des lignes à montrer
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('cereale')->withSearchQuery('céréales coussins')->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted(['p-1'])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('lait')->withRejected(['p-2'])->withSearchQuery('lait demi écrémé')->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('sel')->haveAtHome()->build(),
        ]);
        $this->products->saveAll([new ProductBuilder()->withRetailerProductId('1')->build(), new ProductBuilder()->withRetailerProductId('2')->build()]);

        // When
        $result = $this->loader()->list('user-1', Retailer::LECLERC);

        // Then : « Mes produits » ne montre pas de ligne vide ; le plan, lui, lit la recherche de « cereale » (DrivePlanPostgreSQLLoaderTest)
        $this->assertSame(['farine', 'lait', 'sel'], array_map(static fn(ChoiceDTO $choice): string => $choice->ingredientSlug, $result));
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

    public function testTheNamesAreAskedForTheUserOfTheList(): void
    {
        // Given
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->choices->save(new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->haveAtHome()->build());
        $names = new IngredientNameInMemoryLoader(['farine' => 'Farine']);

        // When
        new ChoicePostgreSQLLoader($this->entityManager, $names)->list('user-1', Retailer::LECLERC);

        // Then
        $this->assertSame([['userId' => 'user-1', 'slugs' => ['farine']]], $names->namesBySlugCalls);
    }

    public function testTheNameOfAnIngredientOfAnotherUserIsNotShown(): void
    {
        // Given : un choix de user-1 sur le slug d'un ingrédient de user-2, un autre sur son propre ingrédient
        $persister = new DataPersister(static::getContainer());
        $persister->saveIngredient(new IngredientBuilder()->isButter()->withName('Sel fin')->withSlug('sel')->withCreator(new UserBuilder()->withId('user-1')->build())->build());
        $persister->saveIngredient(new IngredientBuilder()->isButter()->withName('Farine de blé')->withSlug('farine')->withCreator(new UserBuilder()->withId('user-2')->build())->build());
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->haveAtHome()->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('sel')->haveAtHome()->build(),
        ]);
        $this->entityManager->clear();

        // When : avec le vrai port des noms d'ingrédients
        $result = static::getContainer()->get(ChoiceLoader::class)->list('user-1', Retailer::LECLERC);

        // Then : le nom de son ingrédient pour l'un, le slug seul pour l'autre
        $names = [];
        foreach ($result as $choice) {
            $names[$choice->ingredientSlug] = $choice->ingredientName;
        }
        $this->assertSame(['farine' => 'farine', 'sel' => 'Sel fin'], $names);
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

    public function testTwoAccountsThatAcceptTheSameProductEachListTheirOwnLabel(): void
    {
        // Given : la fiche commune dit « Farine T55 1 kg » ; Alice a lu un autre libellé (accepté) et un autre (refusé), Bob seulement le produit accepté, Chloé rien
        foreach (['alice', 'bob', 'chloe'] as $userId) {
            $this->settings->save(new DriveSettingsBuilder()->withUserId($userId)->build());
        }
        $this->products->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->withLabel('Farine T55 1 kg')->build(),
            new ProductBuilder()->withRetailerProductId('2')->withLabel('Farine complète')->build(),
        ]);
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('alice')->withIngredientSlug('farine')->withAccepted([$this->id('1')])->withRejected([$this->id('2')])
                ->withObservation($this->id('1'), 'Farine T55 – offre du jour', 159)->withObservation($this->id('2'), 'Farine complète – ancien libellé')->build(),
            new ChoiceBuilder()->withUserId('bob')->withIngredientSlug('farine')->withAccepted([$this->id('1')])->withRejected([$this->id('2')])
                ->withObservation($this->id('1'), 'Farine T55 – lot de 2')->build(),
            new ChoiceBuilder()->withUserId('chloe')->withIngredientSlug('farine')->withAccepted([$this->id('1')])->withRejected([$this->id('2')])->build(),
        ]);

        // When
        $labels = fn(string $userId): array => [
            $this->loader()->list($userId, Retailer::LECLERC)[0]->accepted[0]->product->label,
            $this->loader()->list($userId, Retailer::LECLERC)[0]->rejected[0]->label,
        ];

        // Then : sa ligne d'abord, la fiche commune en repli ; le reste d'un produit (identifiant, contenu) est celui de la fiche pour tous
        $this->assertSame(['Farine T55 – offre du jour', 'Farine complète – ancien libellé'], $labels('alice'));
        $this->assertSame(['Farine T55 – lot de 2', 'Farine complète'], $labels('bob'));
        $this->assertSame(['Farine T55 1 kg', 'Farine complète'], $labels('chloe'));
        $this->assertSame(['1', '1000'], [$this->loader()->list('alice', Retailer::LECLERC)[0]->accepted[0]->product->retailerProductId, (string) $this->loader()->list('alice', Retailer::LECLERC)[0]->accepted[0]->product->content->value]);
    }

    public function testTheLabelOfALineOrderTheRejectedProductsAndTheOwnPriceIsNeverListed(): void
    {
        // Given : deux produits refusés dont l'ordre dépend du libellé du compte ; la ligne acceptée a aussi un prix à elle
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->products->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->withLabel('A fiche')->build(),
            new ProductBuilder()->withRetailerProductId('2')->withLabel('B fiche')->build(),
            new ProductBuilder()->withRetailerProductId('3')->withLabel('Produit')->build(),
        ]);
        $this->choices->save(new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted([$this->id('3')])->withRejected([$this->id('1'), $this->id('2')])
            ->withObservation($this->id('1'), 'Z compte')->withObservation($this->id('2'), 'C compte')->withObservation($this->id('3'), 'Produit du compte', 149)->build());

        // When
        $result = $this->loader()->list('user-1', Retailer::LECLERC);

        // Then : les refusés sont rangés par le libellé du compte ; la réponse ne change pas de forme (pas de prix)
        $this->assertSame(['C compte', 'Z compte'], array_map(static fn(ChoiceProductDTO $product): string => $product->label, $result[0]->rejected));
        $this->assertSame(['productId', 'retailerProductId', 'ean', 'label', 'content', 'packSize'], array_keys($result[0]->accepted[0]->product->jsonSerialize()));
    }

    public function testAProductCarriesItsOfferOnlyWhenItHasOne(): void
    {
        // Given : un produit accepté avec offre, un refusé avec offre, un accepté sans
        $offerId = 'c3b2a1d0-5e4f-4a6b-9c8d-7e6f5a4b3c2d';
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->products->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->withOfferId($offerId)->build(),
            new ProductBuilder()->withRetailerProductId('2')->build(),
            new ProductBuilder()->withRetailerProductId('3')->withOfferId($offerId)->build(),
        ]);
        $this->choices->save(new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted([$this->id('1'), $this->id('2')])->withRejected([$this->id('3')])->build());

        // When
        $result = $this->loader(['farine' => 'Farine'])->list('user-1', Retailer::LECLERC);

        // Then : l'offre se lit sur les produits qui en ont une ; la clé est absente de la réponse des autres
        $choice = json_decode(json_encode($result[0], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($offerId, $choice['accepted'][0]['product']['offerId']);
        $this->assertArrayNotHasKey('offerId', $choice['accepted'][1]['product']);
        $this->assertSame($offerId, $choice['rejected'][0]['offerId']);
        $this->assertSame(['productId', 'retailerProductId', 'ean', 'label', 'content', 'packSize'], array_keys($choice['accepted'][1]['product']));
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
