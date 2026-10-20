<?php

namespace Repas\Tests\Drive\Application;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Command\ReplaceIngredientPreference\ReplaceIngredientPreferenceCommand;
use Repas\Drive\Application\Command\ReplaceIngredientPreference\ReplaceIngredientPreferenceHandler;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\Choice;
use Repas\Drive\Domain\Model\PackSize;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ChoiceBuilder;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\Builder\ProductBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\ChoiceInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\DriveSettingsInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ProductInMemoryRepository;

class ReplaceIngredientPreferenceHandlerTest extends TestCase
{
    private const string STORE = '123456';

    private DateTimeImmutable $now;
    private ChoiceInMemoryRepository $choices;
    private ReplaceIngredientPreferenceHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-19 12:00:00');
        $products = new ProductInMemoryRepository(array_map(
            static fn(string $id): Product => new ProductBuilder()->withRetailerProductId($id)->build(),
            ['1', '2', '3', '4', '5', '6', '7'],
        ));
        $products->save(new ProductBuilder()->withStoreId('654321')->withRetailerProductId('8')->build());
        $this->choices = new ChoiceInMemoryRepository([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted([$this->id('1'), $this->id('2')])->withRejected([$this->id('3')])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('sel')->haveAtHome()->build(),
            new ChoiceBuilder()->withUserId('user-2')->withIngredientSlug('farine')->withAccepted([$this->id('1')])->build(),
        ]);
        $settings = new DriveSettingsInMemoryRepository([
            new DriveSettingsBuilder()->withUserId('user-1')->build(),
            new DriveSettingsBuilder()->withUserId('user-2')->build(),
        ]);
        $this->handler = new ReplaceIngredientPreferenceHandler($settings, $products, $this->choices, new UuidGenerator(), new FrozenClock($this->now));
    }

    public function testReordersTheAcceptedProducts(): void
    {
        // When
        $this->replace('user-1', 'farine', accepted: ['2', '1'], rejected: ['3']);

        // Then
        $choice = $this->choice('user-1', 'farine');
        $this->assertSame([$this->id('2'), $this->id('1')], $choice->getAcceptedProductIds());
        $this->assertSame([$this->id('3')], $choice->getRejectedProductIds());
        $this->assertEquals($this->now, $choice->getUpdatedAt());
    }

    public function testThePackSizesFollowTheirProductsWhenThePreferenceIsReplaced(): void
    {
        // Given : le produit 1 est compté en 20 tranches, le produit 2 en 12 (DRV-652)
        $this->choices->save(new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('cheddar')
            ->withAccepted([$this->id('1'), $this->id('2')])->withPackSize($this->id('1'), 20, 'tranche')->withPackSize($this->id('2'), 12, 'tranche')->build());

        // When : l'écran de gestion inverse l'ordre, retire le produit 2 et accepte le produit 4
        $this->replace('user-1', 'cheddar', accepted: ['4', '1'], rejected: []);

        // Then : chaque produit garde sa contenance, le produit retiré perd la sienne, le nouveau n'en a pas
        $choice = $this->choice('user-1', 'cheddar');
        $this->assertSame([$this->id('4'), $this->id('1')], $choice->getAcceptedProductIds());
        $this->assertNull($choice->getPackSize($this->id('4')));
        $this->assertEquals(PackSize::of(20, 'tranche'), $choice->getPackSize($this->id('1')));
        $this->assertNull($choice->getPackSize($this->id('2')));

        // When : le produit 1 est refusé, puis de nouveau accepté
        $this->replace('user-1', 'cheddar', accepted: ['4'], rejected: ['1']);
        $this->replace('user-1', 'cheddar', accepted: ['4', '1'], rejected: []);

        // Then : la contenance d'avant n'est pas revenue
        $this->assertNull($this->choice('user-1', 'cheddar')->getPackSize($this->id('1')));
    }

    public function testRemovesAnAcceptedProductAndRefusesAnotherOne(): void
    {
        // When : A retiré, le refus de 3 levé, 4 refusé
        $this->replace('user-1', 'farine', accepted: ['2'], rejected: ['4']);

        // Then
        $choice = $this->choice('user-1', 'farine');
        $this->assertSame([$this->id('2')], $choice->getAcceptedProductIds());
        $this->assertSame([$this->id('4')], $choice->getRejectedProductIds());
    }

    public function testAcceptsARejectedProductAgain(): void
    {
        // When
        $this->replace('user-1', 'farine', accepted: ['3', '1', '2'], rejected: []);

        // Then
        $choice = $this->choice('user-1', 'farine');
        $this->assertSame([$this->id('3'), $this->id('1'), $this->id('2')], $choice->getAcceptedProductIds());
        $this->assertSame([], $choice->getRejectedProductIds());
    }

    public function testCreatesTheChoiceOfAnIngredientWithoutPreference(): void
    {
        // When
        $this->replace('user-1', 'sucre', accepted: ['5', '6'], rejected: ['7']);

        // Then
        $choice = $this->choice('user-1', 'sucre');
        $this->assertSame([$this->id('5'), $this->id('6')], $choice->getAcceptedProductIds());
        $this->assertSame([$this->id('7')], $choice->getRejectedProductIds());
        $this->assertSame(['user-1', self::STORE], [$choice->getUserId(), $choice->getStoreId()]);
        $this->assertEquals($this->now, $choice->getUpdatedAt());
    }

    public function testAThousandAndFirstPreferenceIsRefusedButAKnownOneStillChanges(): void
    {
        // Given : 998 préférences de plus pour user-1 (farine et sel déjà là : 1 000 en tout), une dans un autre magasin
        $this->choices->saveAll(array_map(
            static fn(int $i): Choice => new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug("ingredient-$i")->build(),
            range(1, 998),
        ));
        $this->choices->save(new ChoiceBuilder()->withUserId('user-1')->withStoreId('654321')->withIngredientSlug('autre-magasin')->build());

        // When : une préférence existante change malgré tout
        $this->replace('user-1', 'farine', accepted: ['2']);

        // Then
        $this->assertSame([$this->id('2')], $this->choice('user-1', 'farine')->getAcceptedProductIds());

        // When / Then : la 1 001e est refusée, rien n'est créé
        try {
            $this->replace('user-1', 'sucre', accepted: ['5']);
            $this->fail('DRIVE_LIMIT_REACHED attendu');
        } catch (DriveException $exception) {
            $this->assertSame(['DRIVE_LIMIT_REACHED', 422], [$exception->getMessage(), $exception->getCode()]);
        }
        $this->assertNull($this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'sucre'));
        // Un autre utilisateur n'est pas concerné ; vider une préférence existante reste permis
        $this->replace('user-2', 'sucre', accepted: ['5']);
        $this->replace('user-1', 'farine');
        $this->assertNull($this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'farine'));
    }

    public function testThePreferencesAreOnlyCountedWhenOneIsCreated(): void
    {
        // When
        $this->replace('user-1', 'farine', accepted: ['2']);
        $this->replace('user-1', 'farine');
        $this->replace('user-1', 'inconnu-sans-produit');

        // Then : modification, suppression et demande vide d'un ingrédient sans préférence ne comptent rien
        $this->assertSame(0, $this->choices->countCalls);

        // When
        $this->replace('user-1', 'sucre', accepted: ['5']);

        // Then
        $this->assertSame(1, $this->choices->countCalls);
    }

    public function testHaveAtHomeReplacesTheProductsAndComesBack(): void
    {
        // When
        $this->replace('user-1', 'farine', haveAtHome: true);

        // Then
        $this->assertTrue($this->choice('user-1', 'farine')->isHaveAtHome());
        $this->assertSame([[], []], [$this->choice('user-1', 'farine')->getAcceptedProductIds(), $this->choice('user-1', 'farine')->getRejectedProductIds()]);

        // When : il rachète du sel
        $this->replace('user-1', 'sel', accepted: ['1']);

        // Then
        $this->assertFalse($this->choice('user-1', 'sel')->isHaveAtHome());
        $this->assertSame([$this->id('1')], $this->choice('user-1', 'sel')->getAcceptedProductIds());
    }

    public function testEmptyListsWithoutHaveAtHomeDeleteTheChoice(): void
    {
        // When
        $this->replace('user-1', 'farine');

        // Then : celle de l'autre utilisateur reste
        $this->assertNull($this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'farine'));
        $this->assertNotNull($this->choices->findOne('user-2', Retailer::LECLERC, self::STORE, 'farine'));

        // When : rien à supprimer
        $this->replace('user-1', 'sucre');

        // Then
        $this->assertNull($this->choices->findOne('user-1', Retailer::LECLERC, self::STORE, 'sucre'));
    }

    public function testOnlyTheChoiceOfTheUserChanges(): void
    {
        // When
        $this->replace('user-2', 'farine', accepted: ['2', '1']);

        // Then
        $this->assertSame([$this->id('1'), $this->id('2')], $this->choice('user-1', 'farine')->getAcceptedProductIds());
        $this->assertSame([$this->id('2'), $this->id('1')], $this->choice('user-2', 'farine')->getAcceptedProductIds());
    }

    /**
     * @param list<string> $accepted
     * @param list<string> $rejected
     */
    #[DataProvider('invalidRequests')]
    public function testAnInvalidRequestIsRefusedAndNothingIsSaved(bool $haveAtHome, array $accepted, array $rejected): void
    {
        // Given
        $before = $this->choices->all();

        // Then
        $this->expectExceptionObject(DriveException::invalidChoice());

        // When
        try {
            $this->replace('user-1', 'farine', $haveAtHome, $accepted, $rejected);
        } finally {
            $this->assertEquals($before, $this->choices->all());
        }
    }

    public static function invalidRequests(): iterable
    {
        yield 'produit inconnu' => [false, ['1', 'inconnu'], []];
        yield 'produit refusé inconnu' => [false, ['1'], ['inconnu']];
        yield 'produit d\'un autre magasin' => [false, ['8'], []];
        yield 'doublon dans les acceptés' => [false, ['1', '1'], []];
        yield 'doublon dans les refusés' => [false, [], ['3', '3']];
        yield 'à la fois accepté et refusé' => [false, ['1'], ['1']];
        yield 'six acceptés' => [false, ['1', '2', '3', '4', '5', '6'], []];
        yield 'vingt et un refusés' => [false, [], array_map('strval', range(100, 120))];
        yield "j'en ai toujours avec des acceptés" => [true, ['1'], []];
        yield "j'en ai toujours avec des refusés" => [true, [], ['3']];
        // Un corps JSON `{"a": …}` donne un tableau à clés texte : les clés ne doivent ni fusionner les deux listes ni masquer un produit
        yield 'objets de mêmes clés : à la fois accepté et refusé' => [false, ['a' => '1'], ['a' => '1']];
        yield "objets de mêmes clés : produit d'un autre magasin masqué" => [false, ['a' => '8'], ['a' => '1']];
        yield 'objets de mêmes clés : produit inconnu masqué' => [false, ['a' => 'inconnu'], ['a' => '3']];
        yield 'objet aux clés numériques : doublon' => [false, ['0' => '1', '5' => '1'], []];
    }

    public function testAStoreThatIsNotTheCurrentOneIsRefused(): void
    {
        // Then
        $this->expectExceptionObject(DriveException::storeMismatch());

        // When
        ($this->handler)(new ReplaceIngredientPreferenceCommand('user-1', Retailer::LECLERC, '654321', 'farine', false, [], []));
    }

    public function testWithoutSettingsTheDriveIsNotConfigured(): void
    {
        // Then
        $this->expectExceptionObject(DriveException::notConfigured());

        // When
        $this->replace('user-9', 'farine', accepted: ['1']);
    }

    /**
     * @param list<string> $accepted retailerProductId
     * @param list<string> $rejected retailerProductId
     */
    private function replace(string $userId, string $slug, bool $haveAtHome = false, array $accepted = [], array $rejected = []): void
    {
        $toId = fn(string $retailerProductId): string => ctype_digit($retailerProductId) && (int) $retailerProductId < 100 ? $this->id($retailerProductId, $retailerProductId === '8' ? '654321' : self::STORE) : $retailerProductId;

        ($this->handler)(new ReplaceIngredientPreferenceCommand($userId, Retailer::LECLERC, self::STORE, $slug, $haveAtHome, array_map($toId, $accepted), array_map($toId, $rejected)));
    }

    private function choice(string $userId, string $slug): Choice
    {
        return $this->choices->findOne($userId, Retailer::LECLERC, self::STORE, $slug);
    }

    private function id(string $retailerProductId, string $storeId = self::STORE): string
    {
        return Product::idFor(Retailer::LECLERC, $storeId, $retailerProductId);
    }
}
