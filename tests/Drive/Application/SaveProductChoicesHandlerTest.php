<?php

namespace Repas\Tests\Drive\Application;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Command\SaveProductChoices\ChoiceAction;
use Repas\Drive\Application\Command\SaveProductChoices\ChoiceIntent;
use Repas\Drive\Application\Command\SaveProductChoices\Placement;
use Repas\Drive\Application\Command\SaveProductChoices\ProductInput;
use Repas\Drive\Application\Command\SaveProductChoices\SaveProductChoicesCommand;
use Repas\Drive\Application\Command\SaveProductChoices\SaveProductChoicesHandler;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\ContentUnit;
use Repas\Drive\Domain\Model\PricePerMeasure;
use Repas\Drive\Domain\Model\PriceUnit;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\ProductContent;
use Repas\Drive\Domain\Model\ProductPrice;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ChoiceBuilder;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\Builder\ProductBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\ChoiceInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\DriveSettingsInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ProductInMemoryRepository;

class SaveProductChoicesHandlerTest extends TestCase
{
    private const string STORE = '123456';

    private DateTimeImmutable $now;
    private ProductInMemoryRepository $products;
    private ChoiceInMemoryRepository $choices;
    private SaveProductChoicesHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-19 12:00:00');
        $this->products = new ProductInMemoryRepository();
        $this->choices = new ChoiceInMemoryRepository();
        $settings = new DriveSettingsInMemoryRepository([
            new DriveSettingsBuilder()->withUserId('user-1')->build(),
            new DriveSettingsBuilder()->withUserId('user-2')->build(),
        ]);
        $this->handler = new SaveProductChoicesHandler($settings, $this->products, $this->choices, new UuidGenerator(), new FrozenClock($this->now));
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

        // Then : une ligne, la dernière lecture de l'agent fait foi ; chacun garde sa préférence
        $this->assertCount(1, $this->products->all());
        $this->assertSame(['Farine T55 1 kg', 155], [$this->products->all()[0]->getLabel(), $this->products->all()[0]->getPrice()->cents]);
        $this->assertCount(2, $this->choices->all());
        $this->assertSame($this->productIds('555111'), $this->accepted('user-1', 'farine'));
        $this->assertSame($this->productIds('555111'), $this->accepted('user-2', 'farine'));
    }

    public function testAKnownProductIsUpdatedWithoutLosingWhatTheAgentDidNotRead(): void
    {
        // Given
        $this->products->save(new ProductBuilder()->withRetailerProductId('555111')->withEan('3017620422003')->withPrice(149, 149)->withLastSeenAt(new DateTimeImmutable('2026-10-01 10:00:00'))->build());

        // When : ni code-barres, ni contenu, ni prix lus
        $this->save('user-1', [$this->add('farine', Placement::PRIMARY, '555111', 'Farine T55 nouveau libellé')]);

        // Then
        $product = $this->products->all()[0];
        $this->assertSame('Farine T55 nouveau libellé', $product->getLabel());
        $this->assertSame('3017620422003', $product->getEan());
        $this->assertEquals(new ProductContent(1000, ContentUnit::G), $product->getContent());
        $this->assertSame(149, $product->getPrice()->cents);
        $this->assertEquals(new DateTimeImmutable('2026-10-18 10:00:00'), $product->getPriceSeenAt());
        $this->assertEquals($this->now, $product->getLastSeenAt());
    }

    public function testTheSameProductSeenTwiceInOneBatchIsMerged(): void
    {
        // When
        $this->save('user-1', [
            $this->add('farine', Placement::PRIMARY, '1', 'Farine', price: new ProductPrice(100)),
            $this->reject('semoule', '1', 'Farine T55', new ProductContent(500, ContentUnit::G)),
        ]);

        // Then
        $this->assertCount(1, $this->products->all());
        $product = $this->products->all()[0];
        $this->assertSame(['Farine T55', 100], [$product->getLabel(), $product->getPrice()->cents]);
        $this->assertEquals(new ProductContent(500, ContentUnit::G), $product->getContent());
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

    private function add(string $slug, Placement $placement, string $retailerProductId, string $label = 'Farine', ?ProductContent $content = null, ?ProductPrice $price = null): ChoiceIntent
    {
        return new ChoiceIntent($slug, ChoiceAction::ADD_PRODUCT, $placement, new ProductInput($retailerProductId, $label, null, $content, $price));
    }

    private function reject(string $slug, string $retailerProductId, string $label = 'Farine', ?ProductContent $content = null): ChoiceIntent
    {
        return new ChoiceIntent($slug, ChoiceAction::REJECT_PRODUCT, null, new ProductInput($retailerProductId, $label, null, $content));
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
