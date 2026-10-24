<?php

namespace Repas\Tests\Drive\OutputAdapter;


use DateTimeImmutable;
use Repas\Drive\Application\Loader\DrivePlanLoader;
use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanCountsDTO;
use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanDTO;
use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanLineDTO;
use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanNeededDTO;
use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanProductDTO;
use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanRejectedDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveStoreDTO;
use Repas\Drive\Application\Query\ListChoices\PackSizeDTO;
use Repas\Drive\Application\Query\ListChoices\ProductContentDTO;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Interface\DepartmentRayonRepository;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Drive\Domain\Model\ContentUnit;
use Repas\Drive\Domain\Model\DepartmentRayonChoice;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\ProductContent;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Service\PackCalculator;
use Repas\Drive\Infrastructure\Loader\DrivePlanPostgreSQLLoader;
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
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryLoader\ShoppingListForDriveInMemoryLoader;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Interface\UserRepository;

class DrivePlanPostgreSQLLoaderTest extends DatabaseTestCase
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

    public function testBuildsThePlanOfASentListLineByLine(): void
    {
        // Given : farine (deux produits classés, un refusé), lait (refusé seulement), poivre (rien), sel (j'en ai toujours)
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->withStore('123456', null, 'https://exemple.leclercdrive.fr/magasin-123456-123456')->build());
        $this->products->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->withLabel('Farine T55 1 kg')->withContent(new ProductContent(1, ContentUnit::KG))->build(),
            new ProductBuilder()->withRetailerProductId('2')->withLabel('Farine T45 250 g')->withEan('3017620422003')->withContent(new ProductContent(250, ContentUnit::G))->build(),
            new ProductBuilder()->withRetailerProductId('3')->withLabel('Farine complète bio')->withContent(null)->build(),
            new ProductBuilder()->withRetailerProductId('4')->withLabel('Lait entier')->build(),
        ]);
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted([$this->id('1'), $this->id('2')])->withRejected([$this->id('3')])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('lait')->withRejected([$this->id('4')])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('sel')->haveAtHome()->build(),
        ]);
        $list = $this->sentList('user-1', [
            new ShoppingListLineForDrive('farine', 'Farine', 300, 'gramme', 'gramme', 'g', 'WEIGHT', 1.0),
            new ShoppingListLineForDrive('lait', 'Lait', 1.5, 'litre', 'litre', 'l', 'VOLUME', 1000.0),
            new ShoppingListLineForDrive('poivre', 'Poivre', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0),
            new ShoppingListLineForDrive('sel', 'Sel', 2, 'cuillere-a-soupe', 'cuillère à soupe', 'cuillère à soupe', null, null),
        ]);

        // When
        $plan = $this->loader($list)->get('user-1', 'list-1');

        // Then : chaque produit a son propre nombre de produits (300 g pour 1 kg : 1 ; pour 250 g : 2)
        $this->assertEquals(new DrivePlanDTO(
            shoppingListId: 'list-1',
            listVersion: 7,
            retailer: 'leclerc',
            store: new DriveStoreDTO('123456', null, 'https://exemple.leclercdrive.fr/magasin-123456-123456'),
            generatedAt: '2026-10-18T10:00:00+00:00',
            counts: new DrivePlanCountsDTO(1, 2, 1),
            lines: [
                new DrivePlanLineDTO(
                    'farine',
                    'Farine',
                    new DrivePlanNeededDTO(300, 'g', 'WEIGHT', 1, 'gramme', 'gramme'),
                    'KNOWN',
                    [
                        new DrivePlanProductDTO(1, '1', null, 'Farine T55 1 kg', new ProductContentDTO(1, 'kg'), 1, 'COMPUTED', null),
                        new DrivePlanProductDTO(2, '2', '3017620422003', 'Farine T45 250 g', new ProductContentDTO(250, 'g'), 2, 'COMPUTED', null),
                    ],
                    [new DrivePlanRejectedDTO('3', 'Farine complète bio')],
                    'Farine',
                ),
                new DrivePlanLineDTO('lait', 'Lait', new DrivePlanNeededDTO(1.5, 'l', 'VOLUME', 1000, 'litre', 'litre'), 'NEW', [], [new DrivePlanRejectedDTO('4', 'Lait entier')], 'Lait'),
                new DrivePlanLineDTO('poivre', 'Poivre', new DrivePlanNeededDTO(1, 'paquet', 'PACKAGE', 1, 'paquet', 'paquet'), 'NEW', [], [], 'Poivre'),
                new DrivePlanLineDTO('sel', 'Sel', new DrivePlanNeededDTO(2, 'cuillère à soupe', null, null, 'cuillere-a-soupe', 'cuillère à soupe'), 'HAVE_AT_HOME', [], [], 'Sel'),
            ],
        ), $plan);
    }

    public function testTheUnitIsNamedByItsNameWhenItHasNoSymbol(): void
    {
        // Given : l'unité « unité » n'a pas de symbole ; un symbole d'espaces ne vaut pas mieux ; un nom trop long est coupé
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $list = $this->sentList('user-1', [
            new ShoppingListLineForDrive('farine', 'Farine', 300, 'gramme', 'gramme', 'g', 'WEIGHT', 1.0),
            new ShoppingListLineForDrive('lait', 'Lait', 1, 'bouteille', 'bouteille', '  ', 'VOLUME', 1000.0),
            new ShoppingListLineForDrive('oeuf', 'Œuf', 6, 'unite', 'unité', '', 'COUNT', 1.0),
            new ShoppingListLineForDrive('poivre', 'Poivre', 1, 'sachet', str_repeat('é', 40), '', null, null),
            new ShoppingListLineForDrive('sel', 'Sel', 1, 'sans-nom', '', '', null, null),
        ]);

        // When
        $plan = $this->loader($list)->get('user-1', 'list-1');

        // Then : jamais une chaîne vide, 32 caractères au plus
        $this->assertSame(
            ['g', 'bouteille', 'unité', str_repeat('é', 32), 'sans-nom'],
            array_map(static fn(DrivePlanLineDTO $line): string => $line->needed->unitSymbol, $plan->lines),
        );
    }

    public function testAProductWithoutAUsableContentIsToCheck(): void
    {
        // Given : un produit sans contenu connu pour une quantité en grammes, un autre pour une unité sans dimension
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->products->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->withContent(null)->build(),
            new ProductBuilder()->withRetailerProductId('2')->withContent(new ProductContent(1, ContentUnit::KG))->build(),
        ]);
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted([$this->id('1')])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('sel')->withAccepted([$this->id('2')])->build(),
        ]);
        $list = $this->sentList('user-1', [
            new ShoppingListLineForDrive('farine', 'Farine', 300, 'gramme', 'gramme', 'g', 'WEIGHT', 1.0),
            new ShoppingListLineForDrive('sel', 'Sel', 2, 'cuillere-a-soupe', 'cuillère à soupe', 'cuillère à soupe', null, null),
        ]);

        // When
        $plan = $this->loader($list)->get('user-1', 'list-1');

        // Then
        $this->assertSame([[null, 'TO_CHECK'], [null, 'TO_CHECK']], array_map(static fn(DrivePlanLineDTO $line): array => [$line->products[0]->packs, $line->products[0]->packsStatus], $plan->lines));
    }

    public function testOnlyTheChoicesOfTheCurrentStoreAndOfTheUserCount(): void
    {
        // Given : user-1 a changé de magasin ; ses anciens choix, comme ceux d'un autre utilisateur, ne comptent pas
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->withStore('654321', null, 'https://autre.leclercdrive.fr/magasin-654321-654321')->build());
        $this->products->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->build(),
            new ProductBuilder()->withStoreId('654321')->withRetailerProductId('2')->build(),
        ]);
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted([$this->id('1')])->withRejected(['x'])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('sel')->haveAtHome()->build(),
            new ChoiceBuilder()->withUserId('user-2')->withStoreId('654321')->withIngredientSlug('farine')->withAccepted([$this->id('2', '654321')])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withStoreId('654321')->withIngredientSlug('lait')->withAccepted([$this->id('2', '654321')])->build(),
        ]);
        $list = $this->sentList('user-1', [
            new ShoppingListLineForDrive('farine', 'Farine', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0),
            new ShoppingListLineForDrive('lait', 'Lait', 1, 'litre', 'litre', 'l', 'VOLUME', 1000.0),
            new ShoppingListLineForDrive('sel', 'Sel', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0),
        ]);

        // When
        $plan = $this->loader($list)->get('user-1', 'list-1');

        // Then : seul le lait est connu dans le magasin courant
        $this->assertSame(['NEW', 'KNOWN', 'NEW'], array_map(static fn(DrivePlanLineDTO $line): string => $line->status, $plan->lines));
        $this->assertSame([], $plan->lines[0]->rejected);
        $this->assertSame('654321', $plan->store->id);
    }

    public function testAProductUnknownToTheCatalogueIsLeftOut(): void
    {
        // Given
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->choices->save(new ChoiceBuilder()->withUserId('user-1')->withAccepted(['produit-inconnu'])->withRejected(['autre-inconnu'])->build());
        $list = $this->sentList('user-1', [new ShoppingListLineForDrive('farine', 'Farine', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0)]);

        // When
        $line = $this->loader($list)->get('user-1', 'list-1')->lines[0];

        // Then : sans produit accepté, la ligne est nouvelle
        $this->assertSame(['NEW', [], []], [$line->status, $line->products, $line->rejected]);
    }

    public function testRefusesWithoutSettings(): void
    {
        // Then
        $this->expectExceptionObject(DriveException::notConfigured());

        // When
        $this->loader($this->sentList('user-1', []))->get('user-1', 'list-1');
    }

    public function testRefusesAListThatIsNotSent(): void
    {
        // Given
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());

        // Then
        $this->expectExceptionObject(DriveException::shoppingListNotSent());

        // When
        $this->loader($this->sentList('user-1', [], 'ACTIVE'))->get('user-1', 'list-1');
    }

    public function testRefusesTheListOfAnotherUser(): void
    {
        // Given
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-2')->build());

        // Then
        $this->expectExceptionObject(DriveException::shoppingListForbidden());

        // When
        $this->loader($this->sentList('user-1', []))->get('user-2', 'list-1');
    }

    public function testAnUnknownListIsNotFound(): void
    {
        // Given
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());

        // Then
        $this->expectException(ShoppingListException::class);
        $this->expectExceptionCode(404);

        // When
        $this->loader(null)->get('user-1', 'list-1');
    }

    public function testReadingCostsAFixedNumberOfQueriesWhateverTheSizeOfTheList(): void
    {
        // Given : une vraie liste envoyée de 12 ingrédients, tous avec une préférence de deux produits et un refus
        $owner = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($owner);
        $this->settings->save(new DriveSettingsBuilder()->withUserId($owner->getId())->build());
        $persister = new DataPersister(static::getContainer());
        $builder = new ShoppingListBuilder()->withId('list-1')->withOwner($owner)->withStatus(ShoppingListStatus::SENT);
        $products = $choices = [];
        foreach (range(1, 12) as $i) {
            $ingredient = new IngredientBuilder()->isMilk()->withSlug("ingredient-$i")->withName("Ingrédient $i")->build();
            $persister->saveIngredient($ingredient);
            $builder->addIngredient($ingredient);
            foreach ([1, 101, 201] as $offset) {
                $products[] = new ProductBuilder()->withRetailerProductId((string) ($i + $offset))->build();
            }
            $choices[] = new ChoiceBuilder()->withUserId($owner->getId())->withIngredientSlug("ingredient-$i")
                ->withAccepted([$this->id((string) ($i + 1)), $this->id((string) ($i + 101))])->withRejected([$this->id((string) ($i + 201))])->withSearchQuery("recherche $i")->build();
        }
        static::getContainer()->get(ShoppingListRepository::class)->save($builder->build());
        $this->products->saveAll($products);
        $this->choices->saveAll($choices);
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When : avec le vrai port de lecture de la liste
        $plan = static::getContainer()->get(DrivePlanLoader::class)->get($owner->getId(), 'list-1');

        // Then : réglages, liste, lignes, préférences avec produits, rayons appris pour les rayons de Repas des lignes (DRV-642)
        $this->assertCount(12, $plan->lines);
        $this->assertSame(12, $plan->counts->known);
        $this->assertSame(5, $counter->count());
        $searches = array_column(array_map(static fn(DrivePlanLineDTO $line): array => [$line->ingredientSlug, $line->searchQuery], $plan->lines), 1, 0);
        $this->assertSame(['recherche 1', 'recherche 12'], [$searches['ingredient-1'], $searches['ingredient-12']]);
        $this->assertSame(1, $counter->byTable()['SELECT drive_department_rayon']);
    }

    public function testALineCountsThePacksWithTheSizeGivenByThePersonInTheUnitOfTheList(): void
    {
        // Given : le cheddar de la liste se compte en tranches (unité sans dimension) ; le produit en contient 20, le produit de secours
        // n'a pas de réponse, un autre produit est compté dans une autre unité, la ligne d'emmental a un produit sans contenance
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->products->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->withLabel('Fromage Cheddar Burger 20 Tranches - 340g')->withContent(new ProductContent(340, ContentUnit::G))->build(),
            new ProductBuilder()->withRetailerProductId('2')->withLabel('Cheddar tranches')->build(),
            new ProductBuilder()->withRetailerProductId('3')->withLabel('Cheddar en portions')->build(),
            new ProductBuilder()->withRetailerProductId('4')->withLabel('Emmental râpé 200 g')->withContent(new ProductContent(200, ContentUnit::G))->build(),
        ]);
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('cheddar')->withAccepted([$this->id('1'), $this->id('2'), $this->id('3')])
                ->withPackSize($this->id('1'), 20, 'tranche')->withPackSize($this->id('3'), 8, 'portion')->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('emmental')->withAccepted([$this->id('4')])->build(),
        ]);
        $list = $this->sentList('user-1', [
            new ShoppingListLineForDrive('cheddar', 'Cheddar', 15, 'tranche', 'tranche', '', null, null),
            new ShoppingListLineForDrive('emmental', 'Emmental', 15, 'tranche', 'tranche', '', null, null),
        ]);

        // When
        $lines = $this->loader($list)->get('user-1', 'list-1')->lines;

        // Then : 15 tranches pour 20 par produit font 1 produit, sans question ; sans réponse, ou avec une réponse dans une autre unité
        // que celle de la ligne, rien ne se calcule (l'agent demande)
        $this->assertSame(
            [
                [1, 'COMPUTED', ['value' => 20.0, 'unitSlug' => 'tranche']],
                [null, 'TO_CHECK', null],
                [null, 'TO_CHECK', ['value' => 8.0, 'unitSlug' => 'portion']],
            ],
            array_map(static fn(DrivePlanProductDTO $product): array => [$product->packs, $product->packsStatus, $product->packSize === null ? null : ['value' => $product->packSize->value, 'unitSlug' => $product->packSize->unitSlug]], $lines[0]->products),
        );
        $this->assertEquals(new PackSizeDTO(20, 'tranche'), $lines[0]->products[0]->packSize);
        $this->assertSame([[null, 'TO_CHECK', null]], array_map(static fn(DrivePlanProductDTO $product): array => [$product->packs, $product->packsStatus, $product->packSize], $lines[1]->products));
        $this->assertSame(['tranche', 'tranche'], array_map(static fn(DrivePlanLineDTO $line): string => $line->needed->unitSlug, $lines));
        $this->assertSame(['tranche', 'tranche'], array_map(static fn(DrivePlanLineDTO $line): string => $line->needed->unitName, $lines));
    }

    public function testASizeInAnotherUnitThanTheLineIsReturnedButIgnoredByTheCalculation(): void
    {
        // Given : la contenance est comptée en tranches, mais la liste compte maintenant le fromage en grammes
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->products->save(new ProductBuilder()->withRetailerProductId('1')->withContent(new ProductContent(340, ContentUnit::G))->build());
        $this->choices->save(new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('cheddar')->withAccepted([$this->id('1')])->withPackSize($this->id('1'), 20, 'tranche')->build());
        $list = $this->sentList('user-1', [new ShoppingListLineForDrive('cheddar', 'Cheddar', 700, 'gramme', 'gramme', 'g', 'WEIGHT', 1.0)]);

        // When
        $product = $this->loader($list)->get('user-1', 'list-1')->lines[0]->products[0];

        // Then : le calcul retombe sur le contenu net (700 g pour 340 g : 3), la réponse reste visible pour l'agent
        $this->assertSame([3, 'COMPUTED'], [$product->packs, $product->packsStatus]);
        $this->assertEquals(new PackSizeDTO(20, 'tranche'), $product->packSize);
    }

    public function testThePackSizeOfAnotherUserOrAnOldStoreIsNeverUsed(): void
    {
        // Given : le même produit compté en tranches chez un autre utilisateur, et dans l'ancien magasin de l'utilisateur
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->products->saveAll([new ProductBuilder()->withRetailerProductId('1')->build(), new ProductBuilder()->withStoreId('654321')->withRetailerProductId('1')->build()]);
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('cheddar')->withAccepted([$this->id('1')])->build(),
            new ChoiceBuilder()->withUserId('user-2')->withIngredientSlug('cheddar')->withAccepted([$this->id('1')])->withPackSize($this->id('1'), 20, 'tranche')->build(),
            new ChoiceBuilder()->withUserId('user-1')->withStoreId('654321')->withIngredientSlug('emmental')->withAccepted([$this->id('1', '654321')])->withPackSize($this->id('1', '654321'), 20, 'tranche')->build(),
        ]);
        $list = $this->sentList('user-1', [
            new ShoppingListLineForDrive('cheddar', 'Cheddar', 15, 'tranche', 'tranche', '', null, null),
            new ShoppingListLineForDrive('emmental', 'Emmental', 15, 'tranche', 'tranche', '', null, null),
        ]);

        // When
        $lines = $this->loader($list)->get('user-1', 'list-1')->lines;

        // Then : aucune contenance, la question reste posée
        $this->assertSame([[null, 'TO_CHECK', null]], array_map(static fn(DrivePlanProductDTO $product): array => [$product->packs, $product->packsStatus, $product->packSize], $lines[0]->products));
        $this->assertSame([], $lines[1]->products);
    }

    public function testASizeCostsNoQueryMore(): void
    {
        // Given
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->products->save(new ProductBuilder()->withRetailerProductId('1')->build());
        $this->choices->save(new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('cheddar')->withAccepted([$this->id('1')])->withPackSize($this->id('1'), 20, 'tranche')->build());
        $list = $this->sentList('user-1', [new ShoppingListLineForDrive('cheddar', 'Cheddar', 15, 'tranche', 'tranche', '', null, null)]);
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->loader($list)->get('user-1', 'list-1');

        // Then : les réglages et les préférences avec leurs produits, comme sans contenance
        $this->assertSame(2, $counter->count());
    }

    public function testALineSearchesWithTheRememberedSearchOfItsChoiceElseWithTheIngredientName(): void
    {
        // Given : un choix avec une recherche, un sans, un choix sans produit accepté mais avec une recherche, un ingrédient sans choix,
        // et « j'en ai toujours » dont la colonne a gardé une recherche d'une version plus ancienne du code
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->products->saveAll([new ProductBuilder()->withRetailerProductId('1')->build(), new ProductBuilder()->withRetailerProductId('2')->build()]);
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('cereale')->withAccepted([$this->id('1')])->withSearchQuery('céréales coussins chocolat')->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted([$this->id('2')])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('lait')->withRejected([$this->id('2')])->withSearchQuery('lait demi écrémé')->build(),
            // DRV-653 : ni produit accepté ni refusé, seulement la recherche (produit pris « cette fois seulement »)
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('beurre')->withSearchQuery('beurre doux plaquette')->build(),
            new ChoiceBuilder()->withId('choice-sel')->withUserId('user-1')->withIngredientSlug('sel')->withAccepted([$this->id('1')])->withSearchQuery('gros sel')->build(),
        ]);
        $this->entityManager->getConnection()->executeStatement("UPDATE drive_choice SET kind = 'HAVE_AT_HOME' WHERE id = 'choice-sel'");
        $list = $this->sentList('user-1', [
            new ShoppingListLineForDrive('cereale', 'Céréale coussin', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0),
            new ShoppingListLineForDrive('farine', 'Farine', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0),
            new ShoppingListLineForDrive('lait', 'Lait', 1, 'litre', 'litre', 'l', 'VOLUME', 1000.0),
            new ShoppingListLineForDrive('beurre', 'Beurre', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0),
            new ShoppingListLineForDrive('poivre', 'Poivre', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0),
            new ShoppingListLineForDrive('sel', 'Sel', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0),
        ]);

        // When
        $lines = $this->loader($list)->get('user-1', 'list-1')->lines;

        // Then : la recherche mémorisée sinon le nom de l'ingrédient ; le nom affiché ne change jamais
        $this->assertSame(
            [
                ['Céréale coussin', 'céréales coussins chocolat', 'KNOWN'],
                ['Farine', 'Farine', 'KNOWN'],
                ['Lait', 'lait demi écrémé', 'NEW'],
                ['Beurre', 'beurre doux plaquette', 'NEW'],
                ['Poivre', 'Poivre', 'NEW'],
                ['Sel', 'Sel', 'HAVE_AT_HOME'],
            ],
            array_map(static fn(DrivePlanLineDTO $line): array => [$line->ingredientName, $line->searchQuery, $line->status], $lines),
        );
    }

    public function testTheSearchOfAnotherRetailerAnotherStoreOrAnotherUserIsNeverUsed(): void
    {
        // Given : le même ingrédient chez une autre enseigne (écrit en SQL : le code ne connaît qu'une enseigne), dans l'ancien magasin du
        // même utilisateur, et chez un autre utilisateur ; « cereale » a aussi sa recherche à elle chez Leclerc
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->products->save(new ProductBuilder()->withRetailerProductId('1')->build());
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('cereale')->withAccepted([$this->id('1')])->withSearchQuery('céréales coussins pour Leclerc')->build(),
            new ChoiceBuilder()->withUserId('user-1')->withStoreId('654321')->withIngredientSlug('sel')->withAccepted([$this->id('1')])->withSearchQuery('recherche de l ancien magasin')->build(),
            new ChoiceBuilder()->withUserId('user-2')->withIngredientSlug('sel')->withAccepted([$this->id('1')])->withSearchQuery('recherche de user-2')->build(),
        ]);
        foreach ([['cereale', 'cereales choco pour une autre enseigne'], ['sel', 'sel fin pour une autre enseigne']] as [$slug, $search]) {
            $this->entityManager->getConnection()->executeStatement(
                "INSERT INTO drive_choice (id, user_id, retailer, store_id, ingredient_slug, kind, search_query, updated_at) VALUES (?, 'user-1', 'autre-enseigne', '123456', ?, 'PRODUCTS', ?, '2026-10-18 10:00:00')",
                ['other-retailer-' . $slug, $slug, $search],
            );
        }
        $list = $this->sentList('user-1', [
            new ShoppingListLineForDrive('cereale', 'Céréale coussin', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0),
            new ShoppingListLineForDrive('sel', 'Sel', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0),
        ]);

        // When
        $lines = $this->loader($list)->get('user-1', 'list-1')->lines;

        // Then : chaque enseigne garde sa recherche, rien n'est repris d'une autre enseigne, d'un autre magasin ni d'un autre utilisateur
        $this->assertSame(['céréales coussins pour Leclerc', 'Sel'], array_map(static fn(DrivePlanLineDTO $line): string => $line->searchQuery, $lines));
    }

    public function testTheLinesCarryTheRayonsOfTheStoreChainLearnedForTheirDepartment(): void
    {
        // Given : deux rayons de Repas ; « boulangerie » a quatre rayons appris dont deux à égalité, « epicerie » un seul
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->learn('boulangerie', 4001, 5);
        $this->learn('boulangerie', 4003, 3);
        $this->learn('boulangerie', 4002, 3);
        $this->learn('boulangerie', 4004, 1);
        $this->learn('epicerie', 4009, 2);
        $list = $this->sentList('user-1', [
            new ShoppingListLineForDrive('farine', 'Farine', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0, 'boulangerie'),
            new ShoppingListLineForDrive('levure', 'Levure', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0, 'boulangerie'),
            new ShoppingListLineForDrive('sel', 'Sel', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0, 'epicerie'),
        ]);

        // When
        $lines = $this->loader($list)->get('user-1', 'list-1')->lines;

        // Then : trois au plus, du plus choisi au moins choisi, à égalité le plus petit identifiant d'abord ; les lignes d'un même
        // rayon de Repas ont les mêmes
        $this->assertSame([[4001, 4002, 4003], [4001, 4002, 4003], [4009]], array_map(static fn(DrivePlanLineDTO $line): array => $line->rayonHints, $lines));
    }

    public function testALineWithoutLearnedRayonOrWithoutDepartmentHasNoHint(): void
    {
        // Given : rien d'appris pour « surgele », une ligne sans rayon de Repas, et des rayons appris par une autre enseigne
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->learn('boulangerie', 4001, 1);
        $this->entityManager->getConnection()->executeStatement("INSERT INTO drive_department_rayon (retailer, department_slug, rayon_id, chosen_count) VALUES ('auchan', 'surgele', 7777, 9)");
        $list = $this->sentList('user-1', [
            new ShoppingListLineForDrive('glace', 'Glace', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0, 'surgele'),
            new ShoppingListLineForDrive('inconnu', 'Inconnu', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0, null),
            new ShoppingListLineForDrive('farine', 'Farine', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0, 'boulangerie'),
        ]);

        // When
        $lines = $this->loader($list)->get('user-1', 'list-1')->lines;

        // Then : les rayons de chaque enseigne restent chez elle
        $this->assertSame([[], [], [4001]], array_map(static fn(DrivePlanLineDTO $line): array => $line->rayonHints, $lines));
    }

    public function testTheHintsAreTheSameForEveryUserAndEveryStoreOfTheChain(): void
    {
        // Given : ce qui est appris ne dépend ni de l'utilisateur ni du magasin (faits de catalogue agrégés)
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->withStore('123456', null, 'https://exemple.leclercdrive.fr/magasin-123456-123456')->build());
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-2')->withStore('654321', null, 'https://autre.leclercdrive.fr/magasin-654321-654321')->build());
        $this->learn('boulangerie', 4001, 2);
        $line = static fn(): array => [new ShoppingListLineForDrive('farine', 'Farine', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0, 'boulangerie')];

        // When
        $first = $this->loader($this->sentList('user-1', $line()))->get('user-1', 'list-1');
        $second = $this->loader($this->sentList('user-2', $line()))->get('user-2', 'list-1');

        // Then
        $this->assertSame([[4001], [4001]], [$first->lines[0]->rayonHints, $second->lines[0]->rayonHints]);
    }

    public function testThePlanOfLinesWithoutDepartmentCostsNoQueryForTheHints(): void
    {
        // Given
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $list = $this->sentList('user-1', [new ShoppingListLineForDrive('farine', 'Farine', 1, 'paquet', 'paquet', 'paquet', 'PACKAGE', 1.0)]);
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->loader($list)->get('user-1', 'list-1');

        // Then : les réglages et les préférences seulement (la liste vient du double)
        $this->assertArrayNotHasKey('SELECT drive_department_rayon', $counter->byTable());
        $this->assertSame(2, $counter->count());
    }

    private function learn(string $departmentSlug, int $rayonId, int $times): void
    {
        static::getContainer()->get(DepartmentRayonRepository::class)->recordChoices(Retailer::LECLERC, array_fill(0, $times, new DepartmentRayonChoice($departmentSlug, $rayonId)));
    }

    /**
     * @param list<ShoppingListLineForDrive> $lines
     */
    private function sentList(string $ownerId, array $lines, string $status = 'SENT'): ShoppingListForDrive
    {
        return new ShoppingListForDrive('list-1', $ownerId, $status, 7, $lines);
    }

    private function loader(?ShoppingListForDrive $list): DrivePlanPostgreSQLLoader
    {
        return new DrivePlanPostgreSQLLoader(
            $this->entityManager,
            new ShoppingListForDriveInMemoryLoader($list),
            new PackCalculator(),
            new FrozenClock(new DateTimeImmutable('2026-10-18T10:00:00+00:00')),
        );
    }

    private function id(string $retailerProductId, string $storeId = '123456'): string
    {
        return Product::idFor(Retailer::LECLERC, $storeId, $retailerProductId);
    }
}
