<?php

namespace Repas\Tests\Drive\Application;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Command\SaveProductChoices\ChoiceAction;
use Repas\Drive\Application\Command\SaveProductChoices\ChoiceIntent;
use Repas\Drive\Application\Command\SaveProductChoices\PackSizeChange;
use Repas\Drive\Application\Command\SaveProductChoices\Placement;
use Repas\Drive\Application\Command\SaveProductChoices\ProductInput;
use Repas\Drive\Application\Command\SaveProductChoices\SaveProductChoicesCommand;
use Repas\Drive\Application\Command\SaveProductChoices\SaveProductChoicesHandler;
use Repas\Drive\Application\Command\SaveProductChoices\SearchQueryChange;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\AcceptedProduct;
use Repas\Drive\Domain\Model\Choice;
use Repas\Drive\Domain\Model\PackSize;
use Repas\Drive\Domain\Model\ContentUnit;
use Repas\Drive\Domain\Model\PricePerMeasure;
use Repas\Drive\Domain\Model\PriceUnit;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\ProductContent;
use Repas\Drive\Domain\Model\ProductPrice;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\SearchQuery;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ChoiceBuilder;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\Builder\ProductBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryLoader\IngredientDepartmentInMemoryResolver;
use Repas\Tests\Helper\InMemoryLoader\UnitSlugInMemoryResolver;
use Repas\Tests\Helper\InMemoryRepository\ChoiceInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\DepartmentRayonInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\DriveSettingsInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ProductInMemoryRepository;

class SaveProductChoicesHandlerTest extends TestCase
{
    private const string STORE = '123456';

    private DateTimeImmutable $now;
    private ProductInMemoryRepository $products;
    private ChoiceInMemoryRepository $choices;
    private DepartmentRayonInMemoryRepository $departmentRayons;
    private IngredientDepartmentInMemoryResolver $ingredientDepartments;
    private UnitSlugInMemoryResolver $unitSlugs;
    private DriveSettingsInMemoryRepository $settings;
    private SaveProductChoicesHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-19 12:00:00');
        $this->products = new ProductInMemoryRepository();
        $this->choices = new ChoiceInMemoryRepository();
        $this->departmentRayons = new DepartmentRayonInMemoryRepository();
        // Rayons de Repas des ingrédients des tests ; tout autre ingrédient (« ingredient-… », « inconnu ») n'en a pas
        $this->ingredientDepartments = new IngredientDepartmentInMemoryResolver(['farine' => 'boulangerie', 'semoule' => 'boulangerie', 'sel' => 'epicerie', 'lait' => 'frais']);
        // Unités de Repas connues des tests de contenance (DRV-652)
        $this->unitSlugs = new UnitSlugInMemoryResolver(['tranche', 'gramme', 'cuillere-a-soupe']);
        $this->settings = new DriveSettingsInMemoryRepository([
            new DriveSettingsBuilder()->withUserId('user-1')->build(),
            new DriveSettingsBuilder()->withUserId('user-2')->build(),
        ]);
        $this->handler = new SaveProductChoicesHandler($this->settings, $this->products, $this->choices, $this->departmentRayons, $this->ingredientDepartments, $this->unitSlugs, new UuidGenerator(), new FrozenClock($this->now));
    }

    public function testAPrimaryProductOnANewIngredientCreatesTheChoiceAndTheProduct(): void
    {
        // When
        $this->save('user-1', [$this->add('farine', Placement::PRIMARY, '555111', 'Farine T55 1 kg', new ProductContent(1000, ContentUnit::G), new ProductPrice(149))]);

        // Then
        $choice = $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'farine');
        $this->assertSame([$this->productId('555111')], $choice->getAcceptedProductIds());
        $this->assertEquals($this->now, $choice->getUpdatedAt());
        $product = $this->products->all()[0];
        $this->assertSame(['555111', 'Farine T55 1 kg', 149], [$product->getRetailerProductId(), $product->getLabel(), $product->getPrice()->cents]);
        $this->assertEquals($this->now, $product->getPriceSeenAt());
        $this->assertEquals($this->now, $product->getLastSeenAt());
    }

    public function testFallbackThenPrimaryShiftsTheRanks(): void
    {
        // When
        $this->save('user-1', [$this->add('farine', Placement::PRIMARY, '1')]);
        $this->save('user-1', [$this->add('farine', Placement::FALLBACK, '2')]);
        $this->assertSame($this->productIds('1', '2'), $this->accepted('user-1', 'farine'));
        $this->save('user-1', [$this->add('farine', Placement::PRIMARY, '3')]);

        // Then
        $this->assertSame($this->productIds('3', '1', '2'), $this->accepted('user-1', 'farine'));
    }

    public function testAskingAgainForAKnownProductMovesItUpOrChangesNothing(): void
    {
        // Given
        $this->save('user-1', [$this->add('farine', Placement::FALLBACK, '1'), $this->add('farine', Placement::FALLBACK, '2'), $this->add('farine', Placement::FALLBACK, '3')]);

        // When
        $this->save('user-1', [$this->add('farine', Placement::FALLBACK, '1')]);

        // Then
        $this->assertSame($this->productIds('1', '2', '3'), $this->accepted('user-1', 'farine'));

        // When
        $this->save('user-1', [$this->add('farine', Placement::PRIMARY, '3')]);

        // Then
        $this->assertSame($this->productIds('3', '1', '2'), $this->accepted('user-1', 'farine'));
    }

    public function testIntentsOfOneIngredientAreAppliedInTheOrderReceived(): void
    {
        // When
        $this->save('user-1', [$this->add('farine', Placement::PRIMARY, '1'), $this->add('farine', Placement::PRIMARY, '2'), $this->add('farine', Placement::FALLBACK, '3')]);

        // Then
        $this->assertSame($this->productIds('2', '1', '3'), $this->accepted('user-1', 'farine'));
    }

    public function testRejectingRemovesTheProductFromTheAcceptedOnesAndAddingItBackUnrejectsIt(): void
    {
        // Given
        $this->save('user-1', [$this->add('farine', Placement::PRIMARY, '1'), $this->add('farine', Placement::FALLBACK, '2'), $this->add('farine', Placement::FALLBACK, '3')]);

        // When
        $this->save('user-1', [$this->reject('farine', '2')]);

        // Then
        $choice = $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'farine');
        $this->assertSame($this->productIds('1', '3'), $choice->getAcceptedProductIds());
        $this->assertSame($this->productIds('2'), $choice->getRejectedProductIds());

        // When
        $this->save('user-1', [$this->add('farine', Placement::FALLBACK, '2')]);

        // Then
        $choice = $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'farine');
        $this->assertSame($this->productIds('1', '3', '2'), $choice->getAcceptedProductIds());
        $this->assertSame([], $choice->getRejectedProductIds());
    }

    public function testASixthAcceptedProductIsRefusedAndNothingIsSaved(): void
    {
        // Given
        $this->save('user-1', array_map(fn(string $id) => $this->add('farine', Placement::FALLBACK, $id), ['1', '2', '3', '4', '5']));
        $productCount = count($this->products->all());

        // When
        $this->assertInvalid(fn() => $this->save('user-1', [$this->add('sel', Placement::PRIMARY, '9'), $this->add('farine', Placement::FALLBACK, '6')]));

        // Then : ni le sel, ni le produit 9 ou 6
        $this->assertNull($this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'sel'));
        $this->assertSame($this->productIds('1', '2', '3', '4', '5'), $this->accepted('user-1', 'farine'));
        $this->assertCount($productCount, $this->products->all());
    }

    public function testATwentyFirstRejectedProductIsRefused(): void
    {
        // Given
        $this->save('user-1', array_map(fn(int $i) => $this->reject('farine', (string) $i), range(1, 20)));

        // Then
        $this->assertInvalid(fn() => $this->save('user-1', [$this->reject('farine', '21')]));
        $this->assertCount(20, $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'farine')->getRejectedProductIds());
    }

    public function testAProductBothAddedAndRejectedForAnIngredientIsRefused(): void
    {
        // Then
        foreach ([[Placement::PRIMARY, true], [Placement::FALLBACK, false]] as [$placement, $addFirst]) {
            $intents = [$this->add('farine', $placement, '1'), $this->reject('farine', '1')];
            $this->assertInvalid(fn() => $this->save('user-1', $addFirst ? $intents : array_reverse($intents)));
        }
        $this->assertSame([], $this->choices->all());
        $this->assertSame([], $this->products->all());
    }

    public function testTheSameProductMayBeAcceptedForOneIngredientAndRejectedForAnother(): void
    {
        // When
        $this->save('user-1', [$this->add('farine', Placement::PRIMARY, '1'), $this->reject('farine-complete', '1')]);

        // Then
        $this->assertSame($this->productIds('1'), $this->accepted('user-1', 'farine'));
        $this->assertSame($this->productIds('1'), $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'farine-complete')->getRejectedProductIds());
        $this->assertCount(1, $this->products->all());
    }

    public function testHaveAtHomeErasesTheProductsAndAnAddedProductBuysAgain(): void
    {
        // Given
        $this->save('user-1', [$this->add('sel', Placement::PRIMARY, '1'), $this->reject('sel', '2')]);

        // When
        $this->save('user-1', [new ChoiceIntent('sel', ChoiceAction::HAVE_AT_HOME)]);

        // Then
        $choice = $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'sel');
        $this->assertTrue($choice->isHaveAtHome());
        $this->assertSame([], $choice->getAcceptedProductIds());
        $this->assertSame([], $choice->getRejectedProductIds());

        // When
        $this->save('user-1', [$this->add('sel', Placement::PRIMARY, '3')]);

        // Then
        $choice = $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'sel');
        $this->assertFalse($choice->isHaveAtHome());
        $this->assertSame($this->productIds('3'), $choice->getAcceptedProductIds());
    }

    public function testTwoUsersWhoChooseTheSameProductShareOneProduct(): void
    {
        // When
        $this->save('user-1', [$this->add('farine', Placement::PRIMARY, '555111', 'Farine T55', price: new ProductPrice(149))]);
        $this->save('user-2', [$this->add('farine', Placement::FALLBACK, '555111', 'Farine T55 1 kg', price: new ProductPrice(155))]);

        // Then : une ligne, la première lecture fait foi (libellé et prix du premier) ; chacun garde sa préférence
        $this->assertCount(1, $this->products->all());
        $this->assertSame(['Farine T55', 149], [$this->products->all()[0]->getLabel(), $this->products->all()[0]->getPrice()->cents]);
        $this->assertCount(2, $this->choices->all());
        $this->assertSame($this->productIds('555111'), $this->accepted('user-1', 'farine'));
        $this->assertSame($this->productIds('555111'), $this->accepted('user-2', 'farine'));
    }

    public function testAKnownProductKeepsWhatIsKnownAndOnlyItsSeenDateMoves(): void
    {
        // Given
        $this->products->save(new ProductBuilder()->withRetailerProductId('555111')->withLabel('Farine T55')->withEan('3017620422003')->withPrice(149, 149)->withLastSeenAt(new DateTimeImmutable('2026-10-01 10:00:00'))->build());

        // When : le même produit, lu avec un autre libellé, un autre code-barres, un autre contenu, et un prix
        $this->save('user-1', [$this->add('farine', Placement::PRIMARY, '555111', 'Farine T55 nouveau libellé', content: new ProductContent(100, ContentUnit::G))]);

        // Then
        $product = $this->products->all()[0];
        $this->assertSame('Farine T55', $product->getLabel());
        $this->assertSame('3017620422003', $product->getEan());
        $this->assertEquals(new ProductContent(1000, ContentUnit::G), $product->getContent());
        $this->assertEquals(new ProductContent(1000, ContentUnit::G), $product->getContent());
        $this->assertSame(149, $product->getPrice()->cents);
        $this->assertEquals(new DateTimeImmutable('2026-10-18 10:00:00'), $product->getPriceSeenAt());
        $this->assertEquals($this->now, $product->getLastSeenAt());
    }

    public function testTheRayonAndTheFamilyOfTheStoreAreKeptOnTheProduct(): void
    {
        // When : un produit accepté et un refusé qui portent leur rayon, un autre produit sans rayon (agent plus ancien)
        $this->save('user-1', [
            $this->add('farine', Placement::PRIMARY, '1', rayonId: 4001, familyId: 4101),
            $this->reject('farine', '2', rayonId: 4002),
            $this->add('sel', Placement::PRIMARY, '3'),
        ]);

        // Then
        $byProduct = [];
        foreach ($this->products->all() as $product) {
            $byProduct[$product->getRetailerProductId()] = [$product->getRayonId(), $product->getFamilyId()];
        }
        $this->assertSame(['1' => [4001, 4101], '2' => [4002, null], '3' => [null, null]], $byProduct);
    }

    public function testAKnownProductKeepsItsRayonAndAMissingOneIsFilledByTheNextAgent(): void
    {
        // Given : un produit connu avec son rayon, un autre connu sans rayon (lu par un agent plus ancien)
        $this->products->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->withRayon(4001, 4101)->build(),
            new ProductBuilder()->withRetailerProductId('2')->build(),
        ]);

        // When : un autre utilisateur les renvoie, l'un avec un autre rayon, l'autre avec son rayon ; puis sans aucun rayon
        $this->save('user-2', [
            $this->add('farine', Placement::PRIMARY, '1', rayonId: 4999, familyId: 4999),
            $this->add('sel', Placement::PRIMARY, '2', rayonId: 4002, familyId: 4102),
        ]);
        $this->save('user-1', [$this->add('farine', Placement::FALLBACK, '1'), $this->add('sel', Placement::FALLBACK, '2')]);

        // Then : le rayon connu n'est ni remplacé ni effacé, le manquant est rempli
        $byProduct = [];
        foreach ($this->products->all() as $product) {
            $byProduct[$product->getRetailerProductId()] = [$product->getRayonId(), $product->getFamilyId()];
        }
        $this->assertSame(['1' => [4001, 4101], '2' => [4002, 4102]], $byProduct);
    }

    public function testAChosenProductThatKnowsItsRayonTeachesTheRayonOfTheDepartmentOfItsIngredient(): void
    {
        // When : farine et semoule (rayon de Repas « boulangerie ») choisies dans le rayon 4001 de l'enseigne, le sel (« epicerie ») dans le 4002
        $this->save('user-1', [
            $this->add('farine', Placement::PRIMARY, '1', rayonId: 4001, familyId: 4101),
            $this->add('semoule', Placement::FALLBACK, '2', rayonId: 4001),
            $this->add('sel', Placement::PRIMARY, '3', rayonId: 4002),
        ]);

        // Then : un choix compté par produit choisi, en une seule écriture, après une seule lecture des rayons de Repas
        $this->assertSame(['leclerc|boulangerie|4001' => 2, 'leclerc|epicerie|4002' => 1], $this->departmentRayons->all());
        $this->assertSame([3], $this->departmentRayons->recordChoicesSizes);
        $this->assertSame([['farine', 'semoule', 'sel']], $this->ingredientDepartments->departmentSlugsByIngredientCalls);

        // When : un autre utilisateur choisit encore un produit de ce rayon pour la farine
        $this->save('user-2', [$this->add('farine', Placement::PRIMARY, '4', rayonId: 4001)]);

        // Then : les choix de tous s'additionnent
        $this->assertSame(3, $this->departmentRayons->countFor(Retailer::LECLERC, 'boulangerie', 4001));
    }

    public function testOnlyAnAcceptedProductWithARayonAndAnIngredientWithADepartmentTeaches(): void
    {
        // When : un refus et un rafraîchissement de prix qui portent un rayon, « j'en ai toujours », un produit choisi sans rayon
        // (agent plus ancien), un produit choisi pour un ingrédient dont Repas ne connaît pas le rayon
        $this->save('user-1', [
            $this->reject('farine', '1', rayonId: 4001),
            $this->add('farine', Placement::PRIMARY, '2'),
            $this->refresh('farine', '2', new ProductPrice(100)),
            new ChoiceIntent('sel', ChoiceAction::HAVE_AT_HOME),
            $this->add('ingredient-inconnu', Placement::PRIMARY, '3', rayonId: 4003),
        ]);

        // Then : rien n'est appris, et le rayon de Repas n'est demandé que pour l'ingrédient dont le produit porte un rayon
        $this->assertSame([], $this->departmentRayons->all());
        $this->assertSame([], $this->departmentRayons->recordChoicesSizes);
        $this->assertSame([['ingredient-inconnu']], $this->ingredientDepartments->departmentSlugsByIngredientCalls);
    }

    public function testWithoutAnyRayonTheDepartmentsAreNotEvenRead(): void
    {
        // When : un lot d'un agent qui ne connaît pas les rayons
        $this->save('user-1', [$this->add('farine', Placement::PRIMARY, '1'), $this->add('sel', Placement::PRIMARY, '2')]);

        // Then : comme avant, aucune requête de plus
        $this->assertSame([], $this->ingredientDepartments->departmentSlugsByIngredientCalls);
        $this->assertSame([], $this->departmentRayons->recordChoicesSizes);
    }

    public function testTheRayonOfTheFicheIsTheOneThatCountsNotTheOneOfTheIntent(): void
    {
        // Given : un produit connu dans le rayon 4001, un autre connu sans rayon
        $this->products->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->withRayon(4001)->build(),
            new ProductBuilder()->withRetailerProductId('2')->build(),
        ]);

        // When : l'agent se trompe de rayon pour le premier, ne dit rien pour le second qui est ensuite complété par un autre agent
        $this->save('user-1', [$this->add('farine', Placement::PRIMARY, '1', rayonId: 4999), $this->add('sel', Placement::PRIMARY, '2', rayonId: 4002)]);
        $this->save('user-2', [$this->add('farine', Placement::FALLBACK, '1')]);

        // Then : la fiche fait foi, même quand l'intention ne dit rien
        $this->assertSame(['leclerc|boulangerie|4001' => 2, 'leclerc|epicerie|4002' => 1], $this->departmentRayons->all());
    }

    public function testABatchThatIsRefusedTeachesNothing(): void
    {
        // Given : cinq produits déjà acceptés pour la farine
        $this->save('user-1', array_map(fn(string $id) => $this->add('farine', Placement::FALLBACK, $id), ['1', '2', '3', '4', '5']));

        // When : le lot apprendrait un rayon mais le sixième produit est refusé
        $this->assertInvalid(fn() => $this->save('user-1', [$this->add('sel', Placement::PRIMARY, '9', rayonId: 4002), $this->add('farine', Placement::FALLBACK, '6', rayonId: 4001)]));

        // Then
        $this->assertSame([], $this->departmentRayons->all());
        $this->assertSame([], $this->departmentRayons->recordChoicesSizes);
    }

    public function testALearnedBatchCostsOneReadAndOneWriteWhateverItsSize(): void
    {
        // When : 40 ingrédients (dont 30 connus de Repas), chacun avec un produit choisi qui porte un rayon
        $departments = [];
        $intents = [];
        foreach (range(1, 40) as $i) {
            $intents[] = $this->add("ingredient-$i", Placement::PRIMARY, (string) $i, rayonId: 4000 + ($i % 3));
            if ($i <= 30) {
                $departments["ingredient-$i"] = 'epicerie';
            }
        }
        $this->ingredientDepartments = new IngredientDepartmentInMemoryResolver($departments);
        $this->handler = new SaveProductChoicesHandler($this->settings, $this->products, $this->choices, $this->departmentRayons, $this->ingredientDepartments, $this->unitSlugs, new UuidGenerator(), new FrozenClock($this->now));
        $this->save('user-1', $intents);

        // Then
        $this->assertCount(1, $this->ingredientDepartments->departmentSlugsByIngredientCalls);
        $this->assertCount(40, $this->ingredientDepartments->departmentSlugsByIngredientCalls[0]);
        $this->assertSame([30], $this->departmentRayons->recordChoicesSizes);
    }

    public function testTheSameProductSeenTwiceInOneBatchIsMerged(): void
    {
        // When
        $this->save('user-1', [
            $this->add('farine', Placement::PRIMARY, '1', 'Farine', price: new ProductPrice(100)),
            $this->reject('semoule', '1', 'Farine T55', new ProductContent(500, ContentUnit::G)),
        ]);

        // Then : le libellé et le prix du premier, le contenu que le premier n'avait pas lu est complété
        $this->assertCount(1, $this->products->all());
        $product = $this->products->all()[0];
        $this->assertSame(['Farine', 100], [$product->getLabel(), $product->getPrice()->cents]);
        $this->assertEquals(new ProductContent(500, ContentUnit::G), $product->getContent());
    }

    /**
     * Constat C1 de la revue de sécurité : la fiche d'un produit est commune à tous les utilisateurs du magasin.
     */
    #[DataProvider('actionsOfTheSecondUser')]
    public function testASecondUserCannotRewriteAKnownProduct(string $action): void
    {
        // Given : Alice a mémorisé le produit
        $this->save('user-1', [new ChoiceIntent('farine', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('555111', 'Farine T55 1 kg', '30000000', new ProductContent(1000, ContentUnit::G), new ProductPrice(149)))]);
        $before = $this->products->all()[0];
        $rival = new ProductInput('555111', 'Texte trompeur', '12345678', new ProductContent(100, ContentUnit::G), new ProductPrice(1));

        // When : Mallory renvoie le même identifiant avec un autre libellé, un autre code, un autre contenu et un autre prix
        $this->save('user-2', [$action === 'ADD_PRODUCT'
            ? new ChoiceIntent('semoule', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, $rival)
            : new ChoiceIntent('semoule', ChoiceAction::REJECT_PRODUCT, null, $rival)]);

        // Then : la fiche d'Alice n'a pas bougé (hors date de vue)
        $this->assertCount(1, $this->products->all());
        $product = $this->products->all()[0];
        $this->assertSame(
            ['Farine T55 1 kg', '30000000', 149, 1000.0],
            [$product->getLabel(), $product->getEan(), $product->getPrice()->cents, $product->getContent()->value],
        );
        $this->assertEquals($before->getPriceSeenAt(), $product->getPriceSeenAt());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function actionsOfTheSecondUser(): iterable
    {
        yield 'il accepte le produit' => ['ADD_PRODUCT'];
        yield 'il le refuse' => ['REJECT_PRODUCT'];
    }

    public function testTheOwnerOfAnAcceptanceMayRefreshThePriceByAddingTheProductAgain(): void
    {
        // Given : Alice accepte déjà le produit (149), Mallory ne l'accepte pas
        $this->save('user-1', [$this->add('farine', Placement::PRIMARY, '555111', 'Farine T55 1 kg', price: new ProductPrice(149))]);
        $later = new DateTimeImmutable('2026-10-20 08:00:00');
        $this->handler = new SaveProductChoicesHandler($this->settings, $this->products, $this->choices, $this->departmentRayons, $this->ingredientDepartments, $this->unitSlugs, new UuidGenerator(), new FrozenClock($later));

        // When : Alice renvoie le produit avec son prix du jour, Mallory aussi avec un prix à 1 centime
        $this->save('user-1', [$this->add('farine', Placement::FALLBACK, '555111', 'Farine T55 1 kg', price: new ProductPrice(159))]);
        $this->save('user-2', [$this->add('farine', Placement::PRIMARY, '555111', 'Farine T55 1 kg', price: new ProductPrice(1))]);

        // Then : seul le prix de celle qui accepte déjà le produit compte
        $product = $this->products->all()[0];
        $this->assertSame(159, $product->getPrice()->cents);
        $this->assertEquals($later, $product->getPriceSeenAt());
    }

    public function testAnAddedProductWithoutPriceInTheFicheGetsThePriceOfTheFirstWriter(): void
    {
        // Given : une fiche connue sans prix
        $this->products->save(new ProductBuilder()->withRetailerProductId('555111')->build());

        // When
        $this->save('user-2', [$this->add('farine', Placement::PRIMARY, '555111', price: new ProductPrice(149))]);

        // Then
        $this->assertSame(149, $this->products->all()[0]->getPrice()->cents);
        $this->assertEquals($this->now, $this->products->all()[0]->getPriceSeenAt());
    }

    public function testProductsOutOfBoundsAreRefusedEvenWhenTheKnownFicheWouldKeepItsValues(): void
    {
        // Given
        $this->products->save(new ProductBuilder()->withRetailerProductId('1')->withEan('30000000')->build());

        // When / Then : un code-barres ou un contenu invalide n'est pas ignoré en silence
        $this->assertInvalid(fn() => $this->save('user-2', [new ChoiceIntent('farine', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('1', 'Farine', '12'))]));
        $this->assertInvalid(fn() => $this->save('user-2', [new ChoiceIntent('farine', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('1', 'Farine', null, new ProductContent(0, ContentUnit::G)))]));
        $this->assertSame([], $this->choices->all());
    }

    public function testTheProductWritesOfTheDayAreCountedInTheSettingsOfTheUser(): void
    {
        // When : trois écritures de produits et un « j'en ai toujours » (qui n'écrit aucun produit)
        $this->save('user-1', [
            $this->add('farine', Placement::PRIMARY, '1'),
            $this->reject('farine', '2'),
            $this->refresh('farine', '1', new ProductPrice(100)),
            new ChoiceIntent('sel', ChoiceAction::HAVE_AT_HOME),
        ]);
        $this->save('user-1', [$this->add('sucre', Placement::PRIMARY, '3')]);

        // Then
        $settings = $this->settings->findOneByUser('user-1', Retailer::LECLERC);
        $this->assertSame(4, $settings->getProductWritesCount());
        $this->assertSame('2026-10-19', $settings->getProductWritesDay()->format('Y-m-d'));
        $this->assertSame(0, $this->settings->findOneByUser('user-2', Retailer::LECLERC)->getProductWritesCount());
    }

    public function testABatchWithoutProductDoesNotTouchTheCounter(): void
    {
        // When
        $this->save('user-1', [new ChoiceIntent('sel', ChoiceAction::HAVE_AT_HOME)]);

        // Then
        $this->assertSame(0, $this->settings->findOneByUser('user-1', Retailer::LECLERC)->getProductWritesCount());
        $this->assertNull($this->settings->findOneByUser('user-1', Retailer::LECLERC)->getProductWritesDay());
    }

    public function testTheDailyLimitIsReachedAtTheNextWriteAndNothingIsSaved(): void
    {
        // Given : 4 950 écritures déjà comptées aujourd'hui
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->withProductWrites(new DateTimeImmutable('2026-10-19'), 4950)->build());

        // When : 50 de plus (le plafond lui-même, 5 000) passent
        $this->save('user-1', array_map(fn(int $i): ChoiceIntent => $this->add("ingredient-$i", Placement::PRIMARY, (string) $i), range(1, 50)));

        // Then
        $this->assertSame(5000, $this->settings->findOneByUser('user-1', Retailer::LECLERC)->getProductWritesCount());

        // When : une de plus, refusée
        try {
            $this->save('user-1', [$this->add('sel', Placement::PRIMARY, '999')]);
            $this->fail('DRIVE_LIMIT_REACHED attendu');
        } catch (DriveException $exception) {
            // Then : 422 DRIVE_LIMIT_REACHED, ni préférence ni produit de plus, compteur inchangé
            $this->assertSame(['DRIVE_LIMIT_REACHED', 422], [$exception->getMessage(), $exception->getCode()]);
        }
        $this->assertNull($this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'sel'));
        $this->assertCount(50, $this->products->all());
        $this->assertSame(5000, $this->settings->findOneByUser('user-1', Retailer::LECLERC)->getProductWritesCount());
        // Un autre utilisateur n'est pas concerné
        $this->save('user-2', [$this->add('sel', Placement::PRIMARY, '999')]);
    }

    public function testTheDailyCounterStartsOverTheNextDayUtc(): void
    {
        // Given : le plafond atteint hier
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-1')->withProductWrites(new DateTimeImmutable('2026-10-18'), 5000)->build());

        // When
        $this->save('user-1', [$this->add('farine', Placement::PRIMARY, '1')]);

        // Then
        $settings = $this->settings->findOneByUser('user-1', Retailer::LECLERC);
        $this->assertSame([1, '2026-10-19'], [$settings->getProductWritesCount(), $settings->getProductWritesDay()->format('Y-m-d')]);
    }

    public function testAThousandAndFirstPreferenceIsRefused(): void
    {
        // Given : 999 préférences dans ce magasin, 5 dans un autre magasin et chez un autre utilisateur (non comptées)
        $this->fillChoices('user-1', self::STORE, 999);
        $this->fillChoices('user-1', '654321', 5);
        $this->fillChoices('user-2', self::STORE, 5);

        // When : la 1 000e passe
        $this->save('user-1', [$this->add('derniere', Placement::PRIMARY, '1')]);

        // Then : la 1 001e est refusée, rien d'enregistré (même si l'autre ingrédient du lot existe déjà)
        $this->assertNotNull($this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'derniere'));
        try {
            $this->save('user-1', [$this->add('ingredient-1', Placement::PRIMARY, '2'), $this->add('de-trop', Placement::PRIMARY, '3')]);
            $this->fail('DRIVE_LIMIT_REACHED attendu');
        } catch (DriveException $exception) {
            $this->assertSame(['DRIVE_LIMIT_REACHED', 422], [$exception->getMessage(), $exception->getCode()]);
        }
        $this->assertNull($this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'de-trop'));
        $this->assertCount(1, $this->products->all());
    }

    public function testAtTheLimitPreferencesAlreadyKnownAreStillUpdatedAndPricesRefreshed(): void
    {
        // Given : 1 000 préférences
        $this->fillChoices('user-1', self::STORE, 1000);
        $this->products->save(new ProductBuilder()->withRetailerProductId('1')->build());

        // When : une préférence existante change, un prix d'un ingrédient sans préférence est rafraîchi (aucune création)
        $this->save('user-1', [$this->add('ingredient-1', Placement::PRIMARY, '1'), $this->refresh('inconnu', '1', new ProductPrice(100))]);

        // Then
        $this->assertSame($this->productIds('1'), $this->accepted('user-1', 'ingredient-1'));
        $this->assertNull($this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'inconnu'));
    }

    public function testThePreferencesAreOnlyCountedWhenTheBatchCreatesSome(): void
    {
        // Given
        $this->choices->save(new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->build());

        // When : rien de nouveau
        $this->save('user-1', [$this->add('farine', Placement::PRIMARY, '1')]);

        // Then
        $this->assertSame(0, $this->choices->countCalls);

        // When
        $this->save('user-1', [$this->add('sel', Placement::PRIMARY, '2')]);

        // Then
        $this->assertSame(1, $this->choices->countCalls);
    }

    private function fillChoices(string $userId, string $storeId, int $count): void
    {
        $this->choices->saveAll(array_map(
            static fn(int $i): Choice => new ChoiceBuilder()->withUserId($userId)->withStoreId($storeId)->withIngredientSlug("ingredient-$i")->build(),
            range(1, $count),
        ));
    }

    public function testAnotherStoreOrUserIsNeverTouched(): void
    {
        // Given
        $other = new ChoiceBuilder()->withUserId('user-2')->withIngredientSlug('farine')->withAccepted(['x'])->build();
        $otherStore = new ChoiceBuilder()->withUserId('user-1')->withStoreId('654321')->withIngredientSlug('farine')->withAccepted(['y'])->build();
        $this->choices->saveAll([$other, $otherStore]);

        // When
        $this->save('user-1', [$this->add('farine', Placement::PRIMARY, '1')]);

        // Then
        $this->assertSame(['x'], $this->choices->findOne('user-2', Retailer::LECLERC, self::STORE, 'farine')->getAcceptedProductIds());
        $this->assertSame(['y'], $this->choices->findOne('user-1', Retailer::LECLERC, '654321', 'farine')->getAcceptedProductIds());
        $this->assertSame($this->productIds('1'), $this->accepted('user-1', 'farine'));
    }

    public function testAStoreThatIsNotTheCurrentOneIsRefusedAndNothingIsSaved(): void
    {
        // Then
        $this->expectExceptionObject(DriveException::storeMismatch());

        // When
        try {
            $this->handler->__invoke(new SaveProductChoicesCommand('user-1', Retailer::LECLERC, '654321', [$this->add('farine', Placement::PRIMARY, '1')]));
        } finally {
            $this->assertSame([], $this->choices->all());
            $this->assertSame([], $this->products->all());
        }
    }

    public function testWithoutSettingsTheDriveIsNotConfigured(): void
    {
        // Then
        $this->expectExceptionObject(DriveException::notConfigured());

        // When
        $this->save('user-9', [$this->add('farine', Placement::PRIMARY, '1')]);
    }

    public function testAnEmptyBatchWritesNothing(): void
    {
        // When
        $this->save('user-1', []);

        // Then
        $this->assertSame([], $this->products->saveAllSizes);
        $this->assertSame([], $this->choices->saveAllSizes);
    }

    public function testTheNumberOfQueriesDoesNotDependOnTheSizeOfTheBatch(): void
    {
        // Given : 40 ingrédients, deux produits chacun, dont des refus
        $intents = [];
        foreach (range(1, 40) as $i) {
            $intents[] = $this->add("ingredient-$i", Placement::PRIMARY, (string) $i);
            $intents[] = $this->add("ingredient-$i", Placement::FALLBACK, (string) ($i + 100));
            $intents[] = $this->reject("ingredient-$i", (string) ($i + 200));
        }

        // When
        $this->save('user-1', $intents);

        // Then : une lecture des produits, une des préférences, une écriture de chaque
        $this->assertSame(1, $this->products->findByKeysCalls);
        $this->assertSame(1, $this->choices->findByUserStoreAndSlugsCalls);
        $this->assertSame([120], $this->products->saveAllSizes);
        $this->assertSame([40], $this->choices->saveAllSizes);
    }

    public function testOnlyTheChangedProductsAreWritten(): void
    {
        // Given
        $this->products->save(new ProductBuilder()->withRetailerProductId('1')->build());
        $this->products->save(new ProductBuilder()->withRetailerProductId('2')->build());
        $this->choices->save(new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted([$this->productId('1')])->build());
        $this->products->saveAllSizes = [];

        // When : le produit 2 n'est que lu pour un rafraîchissement de prix, jamais accepté
        $this->save('user-1', [$this->refresh('farine', '1', new ProductPrice(120)), $this->refresh('farine', '2', new ProductPrice(130))]);

        // Then
        $this->assertSame([1], $this->products->saveAllSizes);
    }

    public function testRefreshingThePriceOfAnAcceptedProductChangesTheProductAndNotTheChoice(): void
    {
        // Given
        $this->products->save(new ProductBuilder()->withRetailerProductId('1')->withLabel('Farine')->withPrice(149, 149)->build());
        $choice = new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted([$this->productId('1')])->build();
        $this->choices->save($choice);

        // When
        $this->save('user-1', [$this->refresh('farine', '1', new ProductPrice(159, new PricePerMeasure(159, PriceUnit::KG)))]);

        // Then
        $product = $this->products->all()[0];
        $this->assertSame(159, $product->getPrice()->cents);
        $this->assertEquals($this->now, $product->getPriceSeenAt());
        $this->assertSame('Farine', $product->getLabel());
        $this->assertSame($choice->getAcceptedProductIds(), $this->accepted('user-1', 'farine'));
        $this->assertEquals($choice->getUpdatedAt(), $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'farine')->getUpdatedAt());
    }

    public function testAPriceIsOnlyRefreshedForAProductTheUserAcceptsForThatIngredient(): void
    {
        // Given : produit 1 accepté pour la farine, produit 2 refusé, produit 3 accepté par un autre utilisateur seulement
        $this->products->save(new ProductBuilder()->withRetailerProductId('1')->withPrice(100)->build());
        $this->products->save(new ProductBuilder()->withRetailerProductId('2')->withPrice(200)->build());
        $this->products->save(new ProductBuilder()->withRetailerProductId('3')->withPrice(300)->build());
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted([$this->productId('1')])->withRejected([$this->productId('2')])->build(),
            new ChoiceBuilder()->withUserId('user-2')->withIngredientSlug('farine')->withAccepted([$this->productId('3')])->build(),
        ]);

        // When : un autre ingrédient, un produit refusé, le produit d'un autre utilisateur, un produit inconnu, un ingrédient sans préférence
        $this->save('user-1', [
            $this->refresh('farine', '2', new ProductPrice(999)),
            $this->refresh('farine', '3', new ProductPrice(999)),
            $this->refresh('farine', '404', new ProductPrice(999)),
            $this->refresh('sucre', '1', new ProductPrice(999)),
        ]);

        // Then : rien ne change, rien n'est créé
        $this->assertSame([100, 200, 300], array_map(static fn(Product $product): int => $product->getPrice()->cents, $this->products->all()));
        $this->assertCount(3, $this->products->all());
        $this->assertNull($this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'sucre'));
    }

    /**
     * @param list<ChoiceIntent> $intents
     */
    #[DataProvider('malformedBatches')]
    public function testMalformedIntentsAreRefusedBeforeAnyRead(array $intents): void
    {
        // When
        $this->assertInvalid(fn() => $this->save('user-1', $intents));

        // Then
        $this->assertSame(0, $this->products->findByKeysCalls);
        $this->assertSame([], $this->choices->all());
    }

    /**
     * @return iterable<string, array{list<ChoiceIntent>}>
     */
    public static function malformedBatches(): iterable
    {
        $product = new ProductInput('1', 'Farine');
        $priceOnly = new ProductInput('1', null, null, null, new ProductPrice(100));

        yield 'plus de 200 intentions' => [array_fill(0, 201, new ChoiceIntent('farine', ChoiceAction::HAVE_AT_HOME))];
        yield 'slug avec majuscule' => [[new ChoiceIntent('Farine', ChoiceAction::HAVE_AT_HOME)]];
        yield 'slug vide' => [[new ChoiceIntent('', ChoiceAction::HAVE_AT_HOME)]];
        yield 'ajout sans produit' => [[new ChoiceIntent('farine', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY)]];
        yield 'ajout sans placement' => [[new ChoiceIntent('farine', ChoiceAction::ADD_PRODUCT, null, $product)]];
        yield 'ajout sans libellé' => [[new ChoiceIntent('farine', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('1'))]];
        yield 'refus sans produit' => [[new ChoiceIntent('farine', ChoiceAction::REJECT_PRODUCT)]];
        yield 'refus avec placement' => [[new ChoiceIntent('farine', ChoiceAction::REJECT_PRODUCT, Placement::PRIMARY, $product)]];
        yield 'refus sans libellé' => [[new ChoiceIntent('farine', ChoiceAction::REJECT_PRODUCT, null, new ProductInput('1'))]];
        yield "j'en ai toujours avec un produit" => [[new ChoiceIntent('farine', ChoiceAction::HAVE_AT_HOME, null, $product)]];
        yield "j'en ai toujours avec un placement" => [[new ChoiceIntent('farine', ChoiceAction::HAVE_AT_HOME, Placement::PRIMARY)]];
        yield 'rafraîchissement sans produit' => [[new ChoiceIntent('farine', ChoiceAction::REFRESH_PRICE)]];
        yield 'rafraîchissement sans prix' => [[new ChoiceIntent('farine', ChoiceAction::REFRESH_PRICE, null, $product)]];
        yield 'rafraîchissement avec placement' => [[new ChoiceIntent('farine', ChoiceAction::REFRESH_PRICE, Placement::PRIMARY, $priceOnly)]];
        yield 'identifiant de produit non numérique' => [[new ChoiceIntent('farine', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('abc', 'Farine'))]];
        yield 'identifiant de produit trop long' => [[new ChoiceIntent('farine', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('1234567890123', 'Farine'))]];
        yield 'rayon nul' => [[new ChoiceIntent('farine', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('1', 'Farine', rayonId: 0))]];
        yield 'rayon négatif' => [[new ChoiceIntent('farine', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('1', 'Farine', rayonId: -4001))]];
        yield 'rayon trop grand pour un INT' => [[new ChoiceIntent('farine', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('1', 'Farine', rayonId: 2147483648))]];
        yield 'famille nulle' => [[new ChoiceIntent('farine', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('1', 'Farine', familyId: 0))]];
        yield 'famille trop grande pour un INT' => [[new ChoiceIntent('farine', ChoiceAction::REJECT_PRODUCT, null, new ProductInput('1', 'Farine', familyId: 2147483648))]];
        yield 'rayon hors bornes sur un rafraîchissement de prix' => [[new ChoiceIntent('farine', ChoiceAction::REFRESH_PRICE, null, new ProductInput('1', null, null, null, new ProductPrice(100), 0))]];
    }

    /**
     * @param ProductInput $product
     */
    #[DataProvider('productsOutOfBounds')]
    public function testProductsOutOfBoundsAreRefusedAndNothingIsSaved(ProductInput $product): void
    {
        // When
        $this->assertInvalid(fn() => $this->save('user-1', [$this->add('sel', Placement::PRIMARY, '9'), new ChoiceIntent('farine', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, $product)]));

        // Then
        $this->assertSame([], $this->choices->all());
        $this->assertSame([], $this->products->all());
    }

    /**
     * @return iterable<string, array{ProductInput}>
     */
    public static function productsOutOfBounds(): iterable
    {
        yield 'libellé vide' => [new ProductInput('1', '  ')];
        yield 'libellé trop long' => [new ProductInput('1', str_repeat('a', 256))];
        yield 'code-barres invalide' => [new ProductInput('1', 'Farine', '12')];
        yield 'contenu nul' => [new ProductInput('1', 'Farine', null, new ProductContent(0, ContentUnit::G))];
        yield 'contenu démesuré' => [new ProductInput('1', 'Farine', null, new ProductContent(1e9, ContentUnit::KG))];
    }

    public function testTheSearchThatFoundTheProductIsRememberedWithThePreference(): void
    {
        // When
        $this->save('user-1', [$this->addFoundBy('cereale', Placement::PRIMARY, '555111', 'céréales coussins chocolat')]);

        // Then
        $choice = $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'cereale');
        $this->assertSame('céréales coussins chocolat', $choice->getSearchQuery()?->value);
        $this->assertSame($this->productIds('555111'), $choice->getAcceptedProductIds());
    }

    public function testTheSearchIsNormalizedBeforeItIsRemembered(): void
    {
        // When
        $this->save('user-1', [$this->addFoundBy('cereale', Placement::PRIMARY, '1', "  céréales \u{00A0}  coussins  ")]);

        // Then
        $this->assertSame('céréales coussins', $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'cereale')->getSearchQuery()?->value);
    }

    public function testAnIntentWithoutTheKeyKeepsTheRememberedSearch(): void
    {
        // Given : une ancienne application n'envoie pas la clé
        $this->save('user-1', [$this->addFoundBy('cereale', Placement::PRIMARY, '1', 'céréales coussins')]);

        // When
        $this->save('user-1', [$this->add('cereale', Placement::PRIMARY, '2'), $this->add('cereale', Placement::FALLBACK, '3')]);
        $this->save('user-1', [$this->reject('cereale', '4'), $this->refresh('cereale', '2', new ProductPrice(100))]);

        // Then : les produits ont changé, la recherche non
        $choice = $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'cereale');
        $this->assertSame($this->productIds('2', '1', '3'), $choice->getAcceptedProductIds());
        $this->assertSame('céréales coussins', $choice->getSearchQuery()?->value);
    }

    public function testAnExplicitNullForgetsTheRememberedSearch(): void
    {
        // Given
        $this->save('user-1', [$this->addFoundBy('cereale', Placement::PRIMARY, '1', 'céréales coussins')]);

        // When
        $this->save('user-1', [new ChoiceIntent('cereale', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('2', 'Farine'), SearchQueryChange::clear())]);

        // Then
        $choice = $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'cereale');
        $this->assertNull($choice->getSearchQuery());
        $this->assertSame($this->productIds('2', '1'), $choice->getAcceptedProductIds());
    }

    public function testATextReplacesTheRememberedSearchAndTheLastIntentOfTheBatchWins(): void
    {
        // Given
        $this->save('user-1', [$this->addFoundBy('cereale', Placement::PRIMARY, '1', 'céréales coussins')]);

        // When
        $this->save('user-1', [$this->addFoundBy('cereale', Placement::PRIMARY, '2', 'coussins chocolat'), $this->addFoundBy('cereale', Placement::FALLBACK, '3', 'cruesli')]);

        // Then
        $this->assertSame('cruesli', $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'cereale')->getSearchQuery()?->value);
    }

    public function testHavingItAtHomeForgetsTheSearchAndABuyingIntentRemembersANewOne(): void
    {
        // Given
        $this->save('user-1', [$this->addFoundBy('cereale', Placement::PRIMARY, '1', 'céréales coussins')]);

        // When
        $this->save('user-1', [new ChoiceIntent('cereale', ChoiceAction::HAVE_AT_HOME)]);

        // Then
        $choice = $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'cereale');
        $this->assertTrue($choice->isHaveAtHome());
        $this->assertNull($choice->getSearchQuery());

        // When : l'utilisateur rachète l'ingrédient
        $this->save('user-1', [$this->addFoundBy('cereale', Placement::PRIMARY, '2', 'coussins chocolat')]);

        // Then
        $choice = $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'cereale');
        $this->assertFalse($choice->isHaveAtHome());
        $this->assertSame('coussins chocolat', $choice->getSearchQuery()?->value);
    }

    public function testBuyingAgainInTheSameBatchAfterHavingItAtHomeKeepsTheSearch(): void
    {
        // When : « j'en ai toujours » puis l'ajout d'un produit avec sa recherche
        $this->save('user-1', [new ChoiceIntent('cereale', ChoiceAction::HAVE_AT_HOME), $this->addFoundBy('cereale', Placement::PRIMARY, '1', 'céréales coussins')]);

        // Then
        $choice = $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'cereale');
        $this->assertFalse($choice->isHaveAtHome());
        $this->assertSame('céréales coussins', $choice->getSearchQuery()?->value);
    }

    public function testTheSearchBelongsToTheUserTheStoreAndTheIngredientOfTheChoice(): void
    {
        // Given : la même préférence chez un autre utilisateur, dans un autre magasin du même utilisateur, et un autre ingrédient
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-2')->withIngredientSlug('cereale')->withSearchQuery('recherche de user-2')->build(),
            new ChoiceBuilder()->withUserId('user-1')->withStoreId('999999')->withIngredientSlug('cereale')->withSearchQuery('recherche de l\'ancien magasin')->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('avoine')->withSearchQuery('recherche de l\'avoine')->build(),
        ]);

        // When
        $this->save('user-1', [$this->addFoundBy('cereale', Placement::PRIMARY, '1', 'céréales coussins')]);

        // Then : chacune garde la sienne, et rien n'est repris d'un autre magasin
        $this->assertSame('céréales coussins', $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'cereale')->getSearchQuery()?->value);
        $this->assertSame('recherche de user-2', $this->choices->findOne('user-2', Retailer::LECLERC, self::STORE, 'cereale')->getSearchQuery()?->value);
        $this->assertSame('recherche de l\'ancien magasin', $this->choices->findOne('user-1', Retailer::LECLERC, '999999', 'cereale')->getSearchQuery()?->value);
        $this->assertSame('recherche de l\'avoine', $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'avoine')->getSearchQuery()?->value);
    }

    public function testChangingTheStoreStartsWithoutAnyRememberedSearch(): void
    {
        // Given : une recherche mémorisée dans l'ancien magasin, puis l'utilisateur change de magasin
        $this->choices->save(new ChoiceBuilder()->withUserId('user-1')->withStoreId('999999')->withIngredientSlug('cereale')->withSearchQuery('recherche de l\'ancien magasin')->build());

        // When : un choix dans le magasin courant, sans recherche
        $this->save('user-1', [$this->add('cereale', Placement::PRIMARY, '1')]);

        // Then : rien n'est repris d'office
        $this->assertNull($this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'cereale')->getSearchQuery());
    }

    /**
     * @param list<ChoiceIntent> $intents
     */
    #[DataProvider('searchQueriesOnOtherActions')]
    public function testASearchOnAnythingButAnAddedProductIsRefusedAndNothingIsSaved(array $intents): void
    {
        // When
        $this->assertInvalid(fn() => $this->save('user-1', [$this->add('sel', Placement::PRIMARY, '9'), ...$intents]));

        // Then
        $this->assertSame([], $this->choices->all());
        $this->assertSame([], $this->products->all());
    }

    /**
     * @return iterable<string, array{list<ChoiceIntent>}>
     */
    public static function searchQueriesOnOtherActions(): iterable
    {
        $text = SearchQueryChange::set(SearchQuery::of('céréales coussins'));
        $product = new ProductInput('1', 'Farine');

        yield 'texte sur un refus' => [[new ChoiceIntent('farine', ChoiceAction::REJECT_PRODUCT, null, $product, $text)]];
        yield "texte sur j'en ai toujours" => [[new ChoiceIntent('farine', ChoiceAction::HAVE_AT_HOME, null, null, $text)]];
        yield 'texte sur un rafraîchissement de prix' => [[new ChoiceIntent('farine', ChoiceAction::REFRESH_PRICE, null, new ProductInput('1', null, null, null, new ProductPrice(100)), $text)]];
        yield "effacement sur j'en ai toujours" => [[new ChoiceIntent('farine', ChoiceAction::HAVE_AT_HOME, null, null, SearchQueryChange::clear())]];
        yield 'effacement sur un refus' => [[new ChoiceIntent('farine', ChoiceAction::REJECT_PRODUCT, null, $product, SearchQueryChange::clear())]];
    }

    public function testTheNumberOfQueriesDoesNotDependOnTheSearchesOfTheBatch(): void
    {
        // Given : 40 ingrédients avec leur recherche
        $intents = [];
        foreach (range(1, 40) as $i) {
            $intents[] = $this->addFoundBy("ingredient-$i", Placement::PRIMARY, (string) $i, "recherche $i");
        }

        // When
        $this->save('user-1', $intents);

        // Then : une lecture des préférences, une écriture : la recherche voyage avec la préférence
        $this->assertSame(1, $this->choices->findByUserStoreAndSlugsCalls);
        $this->assertSame([40], $this->choices->saveAllSizes);
        $this->assertSame('recherche 7', $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'ingredient-7')->getSearchQuery()?->value);
    }

    public function testAPackSizeIsRememberedOnTheLineOfTheProductItWasGivenFor(): void
    {
        // When : le cheddar de la liste se compte en tranches, le produit en contient 20
        $this->save('user-1', [$this->addWithSize('cheddar', Placement::PRIMARY, '1', PackSizeChange::set(PackSize::of(20, 'tranche')))]);

        // Then
        $choice = $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'cheddar');
        $this->assertEquals(PackSize::of(20, 'tranche'), $choice->getPackSize($this->productId('1')));
        $this->assertEquals([new AcceptedProduct($this->productId('1'), 1, PackSize::of(20, 'tranche'))], $choice->getAccepted());
    }

    public function testNoKeyKeepsThePackSizeOfTheSameProductAndAnUnknownProductHasNone(): void
    {
        // Given : le produit 1 est mémorisé avec sa contenance
        $this->save('user-1', [$this->addWithSize('cheddar', Placement::PRIMARY, '1', PackSizeChange::set(PackSize::of(20, 'tranche')))]);

        // When : une ancienne application redit le même produit, sans contenance (ni changement de place, ni refus)
        $this->save('user-1', [$this->add('cheddar', Placement::FALLBACK, '1')]);
        $this->save('user-1', [$this->add('cheddar', Placement::PRIMARY, '1')]);

        // Then : elle est gardée
        $this->assertEquals(PackSize::of(20, 'tranche'), $this->packSize('user-1', 'cheddar', '1'));

        // When : un autre produit devient le préféré, sans contenance
        $this->save('user-1', [$this->add('cheddar', Placement::PRIMARY, '2')]);

        // Then : le nouveau n'a rien, et surtout pas celle de l'ancien ; l'ancien garde la sienne (une contenance par produit)
        $this->assertNull($this->packSize('user-1', 'cheddar', '2'));
        $this->assertEquals(PackSize::of(20, 'tranche'), $this->packSize('user-1', 'cheddar', '1'));
        $this->assertSame($this->productIds('2', '1'), $this->accepted('user-1', 'cheddar'));
    }

    public function testANullPackSizeForgetsItAndAnObjectReplacesIt(): void
    {
        // Given
        $this->save('user-1', [$this->addWithSize('cheddar', Placement::PRIMARY, '1', PackSizeChange::set(PackSize::of(20, 'tranche')))]);

        // When : la personne corrige sa réponse
        $this->save('user-1', [$this->addWithSize('cheddar', Placement::PRIMARY, '1', PackSizeChange::set(PackSize::of(24, 'tranche')))]);

        // Then
        $this->assertEquals(PackSize::of(24, 'tranche'), $this->packSize('user-1', 'cheddar', '1'));

        // When : elle ne sait plus (clé à null)
        $this->save('user-1', [$this->addWithSize('cheddar', Placement::PRIMARY, '1', PackSizeChange::clear())]);

        // Then
        $this->assertNull($this->packSize('user-1', 'cheddar', '1'));
        $this->assertSame($this->productIds('1'), $this->accepted('user-1', 'cheddar'));

        // When : effacer ce qui n'existe pas ne change rien
        $this->save('user-1', [$this->addWithSize('cheddar', Placement::PRIMARY, '1', PackSizeChange::clear())]);

        // Then
        $this->assertNull($this->packSize('user-1', 'cheddar', '1'));
    }

    public function testEachProductOfAChoiceKeepsItsOwnPackSizeAndTheLastOneOfTheBatchWins(): void
    {
        // When : trois produits, dont le premier est redit avec une autre contenance dans le même lot
        $this->save('user-1', [
            $this->addWithSize('cheddar', Placement::PRIMARY, '1', PackSizeChange::set(PackSize::of(20, 'tranche'))),
            $this->addWithSize('cheddar', Placement::FALLBACK, '2', PackSizeChange::set(PackSize::of(12, 'tranche'))),
            $this->add('cheddar', Placement::FALLBACK, '3'),
            $this->addWithSize('cheddar', Placement::FALLBACK, '1', PackSizeChange::set(PackSize::of(16, 'tranche'))),
        ]);

        // Then
        $this->assertEquals(PackSize::of(16, 'tranche'), $this->packSize('user-1', 'cheddar', '1'));
        $this->assertEquals(PackSize::of(12, 'tranche'), $this->packSize('user-1', 'cheddar', '2'));
        $this->assertNull($this->packSize('user-1', 'cheddar', '3'));
    }

    public function testRejectingAProductForgetsItsPackSizeAndAcceptingItAgainDoesNotBringItBack(): void
    {
        // Given
        $this->save('user-1', [
            $this->addWithSize('cheddar', Placement::PRIMARY, '1', PackSizeChange::set(PackSize::of(20, 'tranche'))),
            $this->addWithSize('cheddar', Placement::FALLBACK, '2', PackSizeChange::set(PackSize::of(12, 'tranche'))),
        ]);

        // When
        $this->save('user-1', [$this->reject('cheddar', '1')]);

        // Then
        $this->assertNull($this->packSize('user-1', 'cheddar', '1'));
        $this->assertEquals(PackSize::of(12, 'tranche'), $this->packSize('user-1', 'cheddar', '2'));

        // When
        $this->save('user-1', [$this->add('cheddar', Placement::PRIMARY, '1')]);

        // Then
        $this->assertNull($this->packSize('user-1', 'cheddar', '1'));
        $this->assertEquals(PackSize::of(12, 'tranche'), $this->packSize('user-1', 'cheddar', '2'));
    }

    public function testHavingItAtHomeForgetsThePackSizes(): void
    {
        // Given
        $this->save('user-1', [$this->addWithSize('cheddar', Placement::PRIMARY, '1', PackSizeChange::set(PackSize::of(20, 'tranche')))]);

        // When
        $this->save('user-1', [new ChoiceIntent('cheddar', ChoiceAction::HAVE_AT_HOME)]);
        $this->save('user-1', [$this->add('cheddar', Placement::PRIMARY, '1')]);

        // Then
        $this->assertNull($this->packSize('user-1', 'cheddar', '1'));
    }

    public function testThePackSizeBelongsToTheUserTheStoreAndTheIngredientOfTheChoice(): void
    {
        // Given : le même produit chez un autre utilisateur, dans un autre magasin du même utilisateur, et pour un autre ingrédient
        $productId = $this->productId('1');
        $this->choices->saveAll([
            new ChoiceBuilder()->withUserId('user-2')->withIngredientSlug('cheddar')->withAccepted([$productId])->withPackSize($productId, 30, 'tranche')->build(),
            new ChoiceBuilder()->withUserId('user-1')->withStoreId('999999')->withIngredientSlug('cheddar')->withAccepted([$productId])->withPackSize($productId, 40, 'tranche')->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('emmental')->withAccepted([$productId])->withPackSize($productId, 50, 'tranche')->build(),
        ]);

        // When
        $this->save('user-1', [$this->addWithSize('cheddar', Placement::PRIMARY, '1', PackSizeChange::set(PackSize::of(20, 'tranche')))]);

        // Then : chacune garde la sienne, et rien n'est repris d'un autre magasin ni d'un autre ingrédient
        $this->assertEquals(PackSize::of(20, 'tranche'), $this->packSize('user-1', 'cheddar', '1'));
        $this->assertEquals(PackSize::of(30, 'tranche'), $this->choices->findOne('user-2', Retailer::LECLERC, self::STORE, 'cheddar')->getPackSize($productId));
        $this->assertEquals(PackSize::of(40, 'tranche'), $this->choices->findOne('user-1', Retailer::LECLERC, '999999', 'cheddar')->getPackSize($productId));
        $this->assertEquals(PackSize::of(50, 'tranche'), $this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'emmental')->getPackSize($productId));
    }

    public function testChangingTheStoreStartsWithoutAnyPackSize(): void
    {
        // Given : une contenance mémorisée dans l'ancien magasin
        $this->products->save(new ProductBuilder()->withStoreId('999999')->withRetailerProductId('1')->build());
        $oldProductId = Product::idFor(Retailer::LECLERC, '999999', '1');
        $this->choices->save(new ChoiceBuilder()->withUserId('user-1')->withStoreId('999999')->withIngredientSlug('cheddar')->withAccepted([$oldProductId])->withPackSize($oldProductId, 20, 'tranche')->build());

        // When : le même produit chez l'enseigne, choisi dans le magasin courant
        $this->save('user-1', [$this->add('cheddar', Placement::PRIMARY, '1')]);

        // Then : rien n'est repris d'office
        $this->assertNull($this->packSize('user-1', 'cheddar', '1'));
    }

    public function testAnUnknownUnitIsRefusedAndNothingIsSaved(): void
    {
        // When : la deuxième intention du lot donne une contenance dans une unité qui n'existe pas
        try {
            $this->save('user-1', [
                $this->add('sel', Placement::PRIMARY, '9'),
                $this->addWithSize('cheddar', Placement::PRIMARY, '1', PackSizeChange::set(PackSize::of(20, 'tranche'))),
                $this->addWithSize('cheddar', Placement::FALLBACK, '2', PackSizeChange::set(PackSize::of(20, 'parts-de-gateau'))),
            ]);
            $this->fail('DRIVE_PACK_SIZE_INVALID attendu');
        } catch (DriveException $exception) {
            // Then : tout ou rien
            $this->assertSame(['DRIVE_PACK_SIZE_INVALID', 422], [$exception->getMessage(), $exception->getCode()]);
        }
        $this->assertSame([], $this->choices->all());
        $this->assertSame([], $this->products->all());
    }

    public function testTheUnitsAreCheckedInASingleReadAndOnlyWhenTheBatchGivesASize(): void
    {
        // When : trois contenances dans deux unités, deux fois la même
        $this->save('user-1', [
            $this->addWithSize('cheddar', Placement::PRIMARY, '1', PackSizeChange::set(PackSize::of(20, 'tranche'))),
            $this->addWithSize('emmental', Placement::PRIMARY, '2', PackSizeChange::set(PackSize::of(15, 'tranche'))),
            $this->addWithSize('beurre', Placement::PRIMARY, '3', PackSizeChange::set(PackSize::of(30, 'gramme'))),
        ]);

        // Then
        $this->assertSame([['tranche', 'gramme']], $this->unitSlugs->existingSlugsCalls);

        // When : un lot sans contenance, ou qui n'en efface qu'une, ne lit pas les unités
        $this->save('user-1', [$this->add('farine', Placement::PRIMARY, '4'), $this->addWithSize('cheddar', Placement::PRIMARY, '1', PackSizeChange::clear())]);

        // Then
        $this->assertCount(1, $this->unitSlugs->existingSlugsCalls);
    }

    /**
     * @param list<ChoiceIntent> $intents
     */
    #[DataProvider('packSizesOnOtherActions')]
    public function testAPackSizeOnAnythingButAnAddedProductIsRefusedAndNothingIsSaved(array $intents): void
    {
        // When
        $this->assertInvalid(fn() => $this->save('user-1', [$this->add('sel', Placement::PRIMARY, '9'), ...$intents]));

        // Then
        $this->assertSame([], $this->choices->all());
        $this->assertSame([], $this->products->all());
        $this->assertSame([], $this->unitSlugs->existingSlugsCalls);
    }

    /**
     * @return iterable<string, array{list<ChoiceIntent>}>
     */
    public static function packSizesOnOtherActions(): iterable
    {
        $set = PackSizeChange::set(PackSize::of(20, 'tranche'));
        $clear = PackSizeChange::clear();

        yield 'objet sur un refus' => [[new ChoiceIntent('cheddar', ChoiceAction::REJECT_PRODUCT, null, new ProductInput('1', 'Cheddar', null, null, null, null, null, $set))]];
        yield 'null sur un refus' => [[new ChoiceIntent('cheddar', ChoiceAction::REJECT_PRODUCT, null, new ProductInput('1', 'Cheddar', null, null, null, null, null, $clear))]];
        yield 'objet sur un rafraîchissement de prix' => [[new ChoiceIntent('cheddar', ChoiceAction::REFRESH_PRICE, null, new ProductInput('1', null, null, null, new ProductPrice(100), null, null, $set))]];
        yield 'null sur un rafraîchissement de prix' => [[new ChoiceIntent('cheddar', ChoiceAction::REFRESH_PRICE, null, new ProductInput('1', null, null, null, new ProductPrice(100), null, null, $clear))]];
    }

    public function testThePackSizeDoesNotLeakIntoTheCommonProductSheet(): void
    {
        // When
        $this->save('user-1', [$this->addWithSize('cheddar', Placement::PRIMARY, '1', PackSizeChange::set(PackSize::of(20, 'tranche')))]);

        // Then : la fiche commune ne porte que ce que l'enseigne publie
        $product = $this->products->all()[0];
        $this->assertSame('Farine', $product->getLabel());
        $this->assertNull($product->getContent());
    }

    public function testTheNumberOfQueriesDoesNotDependOnThePackSizesOfTheBatch(): void
    {
        // Given : 40 ingrédients avec leur contenance
        $intents = [];
        foreach (range(1, 40) as $i) {
            $intents[] = $this->addWithSize("ingredient-$i", Placement::PRIMARY, (string) $i, PackSizeChange::set(PackSize::of($i, 'tranche')));
        }

        // When
        $this->save('user-1', $intents);

        // Then : une lecture des préférences, une des unités, une écriture : la contenance voyage avec la préférence
        $this->assertSame(1, $this->choices->findByUserStoreAndSlugsCalls);
        $this->assertCount(1, $this->unitSlugs->existingSlugsCalls);
        $this->assertSame([40], $this->choices->saveAllSizes);
        $this->assertEquals(PackSize::of(7, 'tranche'), $this->packSize('user-1', 'ingredient-7', '7'));
    }

    private function addWithSize(string $slug, Placement $placement, string $retailerProductId, PackSizeChange $packSize): ChoiceIntent
    {
        return new ChoiceIntent($slug, ChoiceAction::ADD_PRODUCT, $placement, new ProductInput($retailerProductId, 'Farine', null, null, null, null, null, $packSize));
    }

    private function packSize(string $userId, string $slug, string $retailerProductId): ?PackSize
    {
        return $this->choices->findOne($userId, Retailer::LECLERC, self::STORE, $slug)->getPackSize($this->productId($retailerProductId));
    }

    private function addFoundBy(string $slug, Placement $placement, string $retailerProductId, string $searchQuery): ChoiceIntent
    {
        return new ChoiceIntent($slug, ChoiceAction::ADD_PRODUCT, $placement, new ProductInput($retailerProductId, 'Farine'), SearchQueryChange::set(SearchQuery::of($searchQuery)));
    }

    private function add(string $slug, Placement $placement, string $retailerProductId, string $label = 'Farine', ?ProductContent $content = null, ?ProductPrice $price = null, ?int $rayonId = null, ?int $familyId = null): ChoiceIntent
    {
        return new ChoiceIntent($slug, ChoiceAction::ADD_PRODUCT, $placement, new ProductInput($retailerProductId, $label, null, $content, $price, $rayonId, $familyId));
    }

    private function reject(string $slug, string $retailerProductId, string $label = 'Farine', ?ProductContent $content = null, ?int $rayonId = null, ?int $familyId = null): ChoiceIntent
    {
        return new ChoiceIntent($slug, ChoiceAction::REJECT_PRODUCT, null, new ProductInput($retailerProductId, $label, null, $content, null, $rayonId, $familyId));
    }

    private function refresh(string $slug, string $retailerProductId, ProductPrice $price): ChoiceIntent
    {
        return new ChoiceIntent($slug, ChoiceAction::REFRESH_PRICE, null, new ProductInput($retailerProductId, null, null, null, $price));
    }

    /**
     * @param list<ChoiceIntent> $intents
     */
    private function save(string $userId, array $intents): void
    {
        ($this->handler)(new SaveProductChoicesCommand($userId, Retailer::LECLERC, self::STORE, $intents));
    }

    private function productId(string $retailerProductId): string
    {
        return Product::idFor(Retailer::LECLERC, self::STORE, $retailerProductId);
    }

    /**
     * @return list<string>
     */
    private function productIds(string ...$retailerProductIds): array
    {
        return array_map($this->productId(...), $retailerProductIds);
    }

    /**
     * @return list<string>
     */
    private function accepted(string $userId, string $slug): array
    {
        return $this->choices->findOne($userId, Retailer::LECLERC, self::STORE, $slug)->getAcceptedProductIds();
    }

    private function assertInvalid(callable $action): void
    {
        try {
            $action();
            $this->fail('DRIVE_CHOICE_INVALID attendu');
        } catch (DriveException $exception) {
            $this->assertSame('DRIVE_CHOICE_INVALID', $exception->getMessage());
            $this->assertSame(422, $exception->getCode());
        }
    }
}
