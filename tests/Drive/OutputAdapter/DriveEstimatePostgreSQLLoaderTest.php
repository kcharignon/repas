<?php

namespace Repas\Tests\Drive\OutputAdapter;


use DateTimeImmutable;
use Repas\Drive\Application\Loader\DriveEstimateLoader;
use Repas\Drive\Application\Query\GetDriveEstimate\DriveEstimateDTO;
use Repas\Drive\Application\Query\GetDriveEstimate\DriveEstimateLineDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveStoreDTO;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Drive\Domain\Model\ContentUnit;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\ProductContent;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Service\ListCostEstimator;
use Repas\Drive\Domain\Service\PackCalculator;
use Repas\Drive\Infrastructure\Loader\DriveEstimatePostgreSQLLoader;
use Repas\Drive\Infrastructure\Loader\DriveSettingsPostgreSQLLoader;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Application\Dto\ShoppingListForDrive;
use Repas\Shared\Application\Dto\ShoppingListLineForDrive;
use Repas\Tests\Helper\Builder\ChoiceBuilder;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ProductBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\InMemoryLoader\ShoppingListForDriveInMemoryLoader;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Interface\UserRepository;

class DriveEstimatePostgreSQLLoaderTest extends DatabaseTestCase
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

    public function testEstimatesTheListLineByLineFromThePreferredProduct(): void
    {
        // Given : farine (deux produits classés : seul le rang 1 compte), lait (sans prix), poivre (refusé seulement), sel (j'en ai toujours), beurre (contenu inconnu)
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->withStore('123456', null, 'https://exemple.leclercdrive.fr/magasin-123456-123456')->build());
        $this->products->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->withLabel('Farine T55 500 g')->withContent(new ProductContent(500, ContentUnit::G))->withPrice(129, 258, seenAt: new DateTimeImmutable('2026-10-12 09:30:00'))->build(),
            new ProductBuilder()->withRetailerProductId('2')->withLabel('Farine T45 1 kg')->withContent(new ProductContent(1, ContentUnit::KG))->withPrice(99, seenAt: new DateTimeImmutable('2026-10-18 10:00:00'))->build(),
            new ProductBuilder()->withRetailerProductId('3')->withLabel('Lait entier 1 l')->withContent(new ProductContent(1, ContentUnit::L))->build(),
            new ProductBuilder()->withRetailerProductId('4')->withLabel('Poivre refusé')->withPrice(500)->build(),
            new ProductBuilder()->withRetailerProductId('5')->withLabel('Beurre doux')->withContent(null)->withPrice(250, seenAt: new DateTimeImmutable('2026-10-15 08:00:00'))->build(),
        ]);
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted([$this->id('1'), $this->id('2')])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('lait')->withAccepted([$this->id('3')])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('poivre')->withRejected([$this->id('4')])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('sel')->haveAtHome()->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('beurre')->withAccepted([$this->id('5')])->build(),
        ]);
        $list = $this->list('user-1', [
            new ShoppingListLineForDrive('beurre', 'Beurre', 250, 'gramme', 'gramme', 'g', 'WEIGHT', 1.0),
            new ShoppingListLineForDrive('farine', 'Farine', 1200, 'gramme', 'gramme', 'g', 'WEIGHT', 1.0),
            new ShoppingListLineForDrive('lait', 'Lait', 1.5, 'litre', 'litre', 'l', 'VOLUME', 1000.0),
            new ShoppingListLineForDrive('poivre', 'Poivre', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0),
            new ShoppingListLineForDrive('sel', 'Sel', 2, 'cuillere-a-soupe', 'cuillère à soupe', 'cuillère à soupe', null, null),
            new ShoppingListLineForDrive('thym', 'Thym', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0),
        ]);

        // When
        $estimate = $this->loader($list)->get('user-1', 'list-1', Retailer::default());

        // Then : farine 1,2 kg en paquets de 500 g = 3 x 1,29 € ; seule la farine entre dans le total (5 lignes à acheter)
        $this->assertEquals(new DriveEstimateDTO(
            shoppingListId: 'list-1',
            retailer: 'leclerc',
            store: new DriveStoreDTO('123456', null, 'https://exemple.leclercdrive.fr/magasin-123456-123456'),
            estimatedTotalCents: 387,
            estimatedLines: 1,
            unestimatedLines: 4,
            coverage: 0.2,
            oldestPriceAt: new DateTimeImmutable('2026-10-12 09:30:00')->format(DATE_ATOM),
            newestPriceAt: new DateTimeImmutable('2026-10-12 09:30:00')->format(DATE_ATOM),
            lines: [
                new DriveEstimateLineDTO('beurre', 'Beurre', 'Beurre doux', null, 250, null, new DateTimeImmutable('2026-10-15 08:00:00')->format(DATE_ATOM), 'TO_CHECK'),
                new DriveEstimateLineDTO('farine', 'Farine', 'Farine T55 500 g', 3, 129, 387, new DateTimeImmutable('2026-10-12 09:30:00')->format(DATE_ATOM), 'ESTIMATED'),
                new DriveEstimateLineDTO('lait', 'Lait', 'Lait entier 1 l', null, null, null, null, 'NO_PRICE'),
                new DriveEstimateLineDTO('poivre', 'Poivre', null, null, null, null, null, 'NO_PRODUCT'),
                new DriveEstimateLineDTO('sel', 'Sel', null, null, null, null, null, 'HAVE_AT_HOME'),
                new DriveEstimateLineDTO('thym', 'Thym', null, null, null, null, null, 'NO_PRODUCT'),
            ],
            disclaimer: "Estimation d'après les derniers prix vus ; le prix réel est celui du drive.",
        ), $estimate);
    }

    public function testAPackSizeGivenForThePreferredProductEstimatesALineWithoutDimension(): void
    {
        // Given : 15 tranches de cheddar ; le préféré (compté en 20 tranches) est estimable, le suivant dans la liste n'a pas de réponse
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->products->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->withLabel('Cheddar 20 tranches')->withContent(new ProductContent(340, ContentUnit::G))->withPrice(299, seenAt: new DateTimeImmutable('2026-10-12 09:30:00'))->build(),
            new ProductBuilder()->withRetailerProductId('2')->withLabel('Emmental tranches')->withContent(new ProductContent(200, ContentUnit::G))->withPrice(250, seenAt: new DateTimeImmutable('2026-10-12 09:30:00'))->build(),
        ]);
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('cheddar')->withAccepted([$this->id('1')])->withPackSize($this->id('1'), 20, 'tranche')->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('emmental')->withAccepted([$this->id('2')])->build(),
        ]);
        $list = $this->list('user-1', [
            new ShoppingListLineForDrive('cheddar', 'Cheddar', 15, 'tranche', 'tranche', '', null, null),
            new ShoppingListLineForDrive('emmental', 'Emmental', 15, 'tranche', 'tranche', '', null, null),
        ]);
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $estimate = $this->loader($list)->get('user-1', 'list-1', Retailer::default());

        // Then : le cheddar est estimé, l'emmental reste à vérifier ; aucune requête de plus
        $this->assertSame(
            [['cheddar', 'ESTIMATED', 1, 299], ['emmental', 'TO_CHECK', null, null]],
            array_map(static fn(DriveEstimateLineDTO $line): array => [$line->ingredientSlug, $line->status, $line->packs, $line->subtotalCents], $estimate->lines),
        );
        $this->assertSame(299, $estimate->estimatedTotalCents);
        $this->assertSame(2, $counter->count());
    }

    public function testThePriceDatesAreThoseOfTheLinesOfTheTotal(): void
    {
        // Given : deux prix de dates différentes entrent dans le total
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->products->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->withPrice(100, seenAt: new DateTimeImmutable('2026-10-12 09:30:00'))->build(),
            new ProductBuilder()->withRetailerProductId('2')->withPrice(200, seenAt: new DateTimeImmutable('2026-10-18 10:00:00'))->build(),
        ]);
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted([$this->id('1')])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('sucre')->withAccepted([$this->id('2')])->build(),
        ]);
        $list = $this->list('user-1', [
            new ShoppingListLineForDrive('farine', 'Farine', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0),
            new ShoppingListLineForDrive('sucre', 'Sucre', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0),
        ]);

        // When
        $estimate = $this->loader($list)->get('user-1', 'list-1', Retailer::default());

        // Then
        $this->assertSame([300, 1.0, new DateTimeImmutable('2026-10-12 09:30:00')->format(DATE_ATOM), new DateTimeImmutable('2026-10-18 10:00:00')->format(DATE_ATOM)], [$estimate->estimatedTotalCents, $estimate->coverage, $estimate->oldestPriceAt, $estimate->newestPriceAt]);
    }

    public function testOnlyTheChoicesOfTheCurrentStoreAndOfTheUserCount(): void
    {
        // Given : user-1 a changé de magasin ; ses anciens choix, comme ceux d'un autre utilisateur, ne comptent pas
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->withStore('654321', null, 'https://autre.leclercdrive.fr/magasin-654321-654321')->build());
        $this->products->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->withPrice(100)->build(),
            new ProductBuilder()->withStoreId('654321')->withRetailerProductId('2')->withPrice(200)->build(),
        ]);
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted([$this->id('1')])->build(),
            new ChoiceBuilder()->withUserId('user-2')->withStoreId('654321')->withIngredientSlug('farine')->withAccepted([$this->id('2', '654321')])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withStoreId('654321')->withIngredientSlug('lait')->withAccepted([$this->id('2', '654321')])->build(),
        ]);
        $list = $this->list('user-1', [
            new ShoppingListLineForDrive('farine', 'Farine', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0),
            new ShoppingListLineForDrive('lait', 'Lait', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0),
        ]);

        // When
        $estimate = $this->loader($list)->get('user-1', 'list-1', Retailer::default());

        // Then : seul le lait est connu dans le magasin courant
        $this->assertSame(['NO_PRODUCT', 'ESTIMATED'], array_map(static fn(DriveEstimateLineDTO $line): string => $line->status, $estimate->lines));
        $this->assertSame(200, $estimate->estimatedTotalCents);
        $this->assertSame('654321', $estimate->store->id);
    }

    public function testAProductUnknownToTheCatalogueIsLeftOut(): void
    {
        // Given
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->choices->save(new ChoiceBuilder()->withUserId('user-1')->withAccepted(['produit-inconnu'])->build());
        $list = $this->list('user-1', [new ShoppingListLineForDrive('farine', 'Farine', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0)]);

        // When
        $estimate = $this->loader($list)->get('user-1', 'list-1', Retailer::default());

        // Then
        $this->assertSame(['NO_PRODUCT', 0, null], [$estimate->lines[0]->status, $estimate->estimatedTotalCents, $estimate->oldestPriceAt]);
    }

    public function testAnEstimateIsAvailableWhateverTheStatusOfTheList(): void
    {
        // Given
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());

        // When / Then
        foreach (['ACTIVE', 'PAUSED', 'SENT', 'COMPLETED'] as $status) {
            $this->assertSame('list-1', $this->loader($this->list('user-1', [], $status))->get('user-1', 'list-1', Retailer::default())->shoppingListId);
        }
    }

    public function testAnEmptyListHasNoLineAndNoCoverage(): void
    {
        // Given
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());

        // When
        $estimate = $this->loader($this->list('user-1', []))->get('user-1', 'list-1', Retailer::default());

        // Then
        $this->assertSame([0, 0, 0, 0.0, []], [$estimate->estimatedTotalCents, $estimate->estimatedLines, $estimate->unestimatedLines, $estimate->coverage, $estimate->lines]);
    }

    public function testTheStoreOfAnotherRetailerIsNotTheStoreOfTheRequestedOne(): void
    {
        // Given : l'utilisateur a un magasin chez une autre enseigne (ligne posée directement : ce code n'en connaît qu'une)
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO drive_settings (user_id, retailer, store_id, store_label, base_url, updated_at, product_writes_count) VALUES ('user-1', 'autre-test', '999', NULL, 'https://exemple.invalid/magasin-999', NOW(), 0)",
        );

        // Then : chez l'enseigne demandée, il n'a toujours pas de magasin
        $this->expectExceptionObject(DriveException::notConfigured());

        // When
        $this->loader($this->list('user-1', []))->get('user-1', 'list-1', Retailer::default());
    }

    public function testRefusesWithoutSettings(): void
    {
        // Then
        $this->expectExceptionObject(DriveException::notConfigured());

        // When
        $this->loader($this->list('user-1', []))->get('user-1', 'list-1', Retailer::default());
    }

    public function testRefusesTheListOfAnotherUser(): void
    {
        // Given
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-2')->build());

        // Then
        $this->expectExceptionObject(DriveException::shoppingListForbidden());

        // When
        $this->loader($this->list('user-1', []))->get('user-2', 'list-1', Retailer::default());
    }

    public function testAnUnknownListIsNotFound(): void
    {
        // Given
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());

        // Then
        $this->expectException(ShoppingListException::class);
        $this->expectExceptionCode(404);

        // When
        $this->loader(null)->get('user-1', 'list-1', Retailer::default());
    }

    public function testEstimatingARealListOfTwelveIngredientsCostsAFixedNumberOfQueries(): void
    {
        // Given : une vraie liste de 12 ingrédients (1 l chacun) ; 8 ont un produit préféré de 1 l avec un prix, 4 non (sans préférence,
        // refus seul, sans prix, contenu en poids pour un volume)
        $owner = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($owner);
        $this->settings->save(new DriveSettingsBuilder()->withUserId($owner->getId())->build());
        $persister = new DataPersister(static::getContainer());
        $builder = new ShoppingListBuilder()->withId('list-1')->withOwner($owner)->withStatus(ShoppingListStatus::SENT);
        $products = $choices = [];
        foreach (range(1, 12) as $i) {
            $slug = sprintf('ingredient-%02d', $i);
            $ingredient = new IngredientBuilder()->isMilk()->withSlug($slug)->withName("Ingrédient $i")->build();
            $persister->saveIngredient($ingredient);
            $builder->addIngredient($ingredient);
            $litre = new ProductContent(1, ContentUnit::L);
            $choice = new ChoiceBuilder()->withUserId($owner->getId())->withIngredientSlug($slug);
            if ($i <= 8) {
                // préféré avec un prix daté du 10 + i octobre, puis un produit de secours qui ne doit pas compter
                $products[] = new ProductBuilder()->withRetailerProductId((string) $i)->withContent($litre)->withPrice($i * 100, seenAt: new DateTimeImmutable('2026-10-' . (10 + $i) . ' 09:00:00'))->build();
                $products[] = new ProductBuilder()->withRetailerProductId((string) ($i + 100))->withContent($litre)->withPrice(1)->build();
                $choices[] = $choice->withAccepted([$this->id((string) $i), $this->id((string) ($i + 100))])->build();
            } elseif ($i === 10) {
                $products[] = new ProductBuilder()->withRetailerProductId((string) $i)->withContent($litre)->withPrice(100)->build();
                $choices[] = $choice->withRejected([$this->id((string) $i)])->build();
            } elseif ($i === 11) {
                $products[] = new ProductBuilder()->withRetailerProductId((string) $i)->withContent($litre)->build();
                $choices[] = $choice->withAccepted([$this->id((string) $i)])->build();
            } elseif ($i === 12) {
                $products[] = new ProductBuilder()->withRetailerProductId((string) $i)->withContent(new ProductContent(1, ContentUnit::KG))->withPrice(100)->build();
                $choices[] = $choice->withAccepted([$this->id((string) $i)])->build();
            }
        }
        static::getContainer()->get(ShoppingListRepository::class)->save($builder->build());
        $this->products->saveAll($products);
        $this->choices->saveAll($choices);
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When : avec le vrai port de lecture de la liste
        $estimate = static::getContainer()->get(DriveEstimateLoader::class)->get($owner->getId(), 'list-1', Retailer::default());

        // Then : total 100 + 200 + … + 800 = 3 600 centimes, couverture 8/12, dates du 11 et du 18 octobre ; réglages, liste, lignes, préférences
        $this->assertCount(12, $estimate->lines);
        $this->assertSame([3600, 8, 4], [$estimate->estimatedTotalCents, $estimate->estimatedLines, $estimate->unestimatedLines]);
        $this->assertEqualsWithDelta(8 / 12, $estimate->coverage, 0.0001);
        $this->assertSame([...array_fill(0, 8, 'ESTIMATED'), ...['NO_PRODUCT', 'NO_PRODUCT', 'NO_PRICE', 'TO_CHECK']], array_column($estimate->lines, 'status'));
        $this->assertSame(['2026-10-11', '2026-10-18'], [substr($estimate->oldestPriceAt, 0, 10), substr($estimate->newestPriceAt, 0, 10)]);
        $this->assertSame(4, $counter->count());
    }

    public function testTwoAccountsThatAcceptTheSameProductEachEstimateWithTheirOwnLabelAndPrice(): void
    {
        // Given : la fiche commune dit 1,49 € ; Alice a lu 1,59 € le 12 octobre, Bob 1,29 € le 18 ; Chloé n'a rien de propre à sa ligne
        foreach (['alice', 'bob', 'chloe'] as $userId) {
            $this->settings->save(new DriveSettingsBuilder()->withUserId($userId)->build());
        }
        $this->products->save(new ProductBuilder()->withRetailerProductId('1')->withLabel('Farine T55 1 kg')->withContent(new ProductContent(1, ContentUnit::KG))->withPrice(149, seenAt: new DateTimeImmutable('2026-10-01 08:00:00'))->build());
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('alice')->withIngredientSlug('farine')->withAccepted([$this->id('1')])->withObservation($this->id('1'), 'Farine T55 – offre', 159, new DateTimeImmutable('2026-10-12 09:30:00'))->build(),
            new ChoiceBuilder()->withUserId('bob')->withIngredientSlug('farine')->withAccepted([$this->id('1')])->withObservation($this->id('1'), 'Farine T55 – lot', 129, new DateTimeImmutable('2026-10-18 10:00:00'))->build(),
            new ChoiceBuilder()->withUserId('chloe')->withIngredientSlug('farine')->withAccepted([$this->id('1')])->build(),
        ]);
        $lines = [new ShoppingListLineForDrive('farine', 'Farine', 2, 'kilo', 'kilo', 'kg', 'WEIGHT', 1000.0)];

        // When
        $alice = $this->loader($this->list('alice', $lines))->get('alice', 'list-1', Retailer::default());
        $bob = $this->loader($this->list('bob', $lines))->get('bob', 'list-1', Retailer::default());
        $chloe = $this->loader($this->list('chloe', $lines))->get('chloe', 'list-1', Retailer::default());

        // Then : chacun son libellé, son prix et sa date ; Chloé, sans valeur à elle, retombe sur la fiche commune
        $this->assertSame(['Farine T55 – offre', 159, 318, new DateTimeImmutable('2026-10-12 09:30:00')->format(DATE_ATOM)], $this->lineOf($alice));
        $this->assertSame(['Farine T55 – lot', 129, 258, new DateTimeImmutable('2026-10-18 10:00:00')->format(DATE_ATOM)], $this->lineOf($bob));
        $this->assertSame(['Farine T55 1 kg', 149, 298, new DateTimeImmutable('2026-10-01 08:00:00')->format(DATE_ATOM)], $this->lineOf($chloe));
        $this->assertSame([318, 258, 298], [$alice->estimatedTotalCents, $bob->estimatedTotalCents, $chloe->estimatedTotalCents]);
    }

    public function testThePriceAndItsDateAlwaysComeFromTheSameSide(): void
    {
        // Given : la ligne n'a qu'un libellé à elle, la fiche commune a un prix daté ; une autre ligne a un prix à elle, la fiche aucun
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->products->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->withLabel('Farine')->withPrice(149, seenAt: new DateTimeImmutable('2026-10-01 08:00:00'))->build(),
            new ProductBuilder()->withRetailerProductId('2')->withLabel('Sucre')->build(),
        ]);
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted([$this->id('1')])->withObservation($this->id('1'), 'Farine du compte')->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('sucre')->withAccepted([$this->id('2')])->withObservation($this->id('2'), null, 99, new DateTimeImmutable('2026-10-20 08:00:00'))->build(),
        ]);
        $list = $this->list('user-1', [
            new ShoppingListLineForDrive('farine', 'Farine', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0),
            new ShoppingListLineForDrive('sucre', 'Sucre', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0),
        ]);

        // When
        $estimate = $this->loader($list)->get('user-1', 'list-1', Retailer::default());

        // Then : farine = libellé du compte, prix et date de la fiche ; sucre = libellé de la fiche, prix et date du compte
        $this->assertSame(['Farine du compte', 149, 149, new DateTimeImmutable('2026-10-01 08:00:00')->format(DATE_ATOM)], $this->lineOf($estimate, 0));
        $this->assertSame(['Sucre', 99, 99, new DateTimeImmutable('2026-10-20 08:00:00')->format(DATE_ATOM)], $this->lineOf($estimate, 1));
    }

    public function testALineWithOwnValuesIsEstimatedEvenWhenTheCommonSheetHasNoPrice(): void
    {
        // Given : la fiche commune n'a jamais eu de prix
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->products->save(new ProductBuilder()->withRetailerProductId('1')->build());
        $this->choices->save(new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted([$this->id('1')])->withObservation($this->id('1'), 'Farine', 149)->build());
        $list = $this->list('user-1', [new ShoppingListLineForDrive('farine', 'Farine', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0)]);

        // When
        $estimate = $this->loader($list)->get('user-1', 'list-1', Retailer::default());

        // Then
        $this->assertSame(['ESTIMATED', 149], [$estimate->lines[0]->status, $estimate->estimatedTotalCents]);
    }

    /**
     * @return array{?string, ?int, ?int, ?string} libellé du produit, prix à l'unité, sous-total et date du prix d'une ligne de l'estimation
     */
    private function lineOf(DriveEstimateDTO $estimate, int $index = 0): array
    {
        $line = $estimate->lines[$index];

        return [$line->productLabel, $line->unitPriceCents, $line->subtotalCents, $line->priceSeenAt];
    }

    /**
     * @param list<ShoppingListLineForDrive> $lines
     */
    private function list(string $ownerId, array $lines, string $status = 'ACTIVE'): ShoppingListForDrive
    {
        return new ShoppingListForDrive('list-1', $ownerId, $status, 7, $lines);
    }

    private function loader(?ShoppingListForDrive $list): DriveEstimatePostgreSQLLoader
    {
        return new DriveEstimatePostgreSQLLoader(
            $this->entityManager,
            new DriveSettingsPostgreSQLLoader($this->entityManager, new ControlledDriveFeature(true)),
            new ShoppingListForDriveInMemoryLoader($list),
            new ListCostEstimator(new PackCalculator()),
        );
    }

    private function id(string $retailerProductId, string $storeId = '123456'): string
    {
        return Product::idFor(Retailer::LECLERC, $storeId, $retailerProductId);
    }
}
