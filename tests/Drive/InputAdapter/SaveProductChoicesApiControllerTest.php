<?php

namespace Repas\Tests\Drive\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Drive\Application\Command\SaveProductChoices\ChoiceAction;
use Repas\Drive\Application\Command\SaveProductChoices\ChoiceIntent;
use Repas\Drive\Application\Command\SaveProductChoices\PackSizeChange;
use Repas\Drive\Application\Command\SaveProductChoices\Placement;
use Repas\Drive\Application\Command\SaveProductChoices\ProductInput;
use Repas\Drive\Application\Command\SaveProductChoices\SaveProductChoicesCommand;
use Repas\Drive\Application\Command\SaveProductChoices\SearchQueryChange;
use Repas\Drive\Domain\Model\ContentUnit;
use Repas\Drive\Domain\Model\PackSize;
use Repas\Drive\Domain\Model\PricePerMeasure;
use Repas\Drive\Domain\Model\PriceUnit;
use Repas\Drive\Domain\Model\ProductContent;
use Repas\Drive\Domain\Model\ProductPrice;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\SearchQuery;
use Repas\Drive\Domain\Interface\DriveFeature;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class SaveProductChoicesApiControllerTest extends QueryBusWebTestCase
{
    public function testTheConnectedUserSavesABatchOfIntents(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($user, [
            'retailer' => 'leclerc',
            'storeId' => '123456',
            'choices' => [
                ['ingredientSlug' => 'farine', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => [
                    'retailerProductId' => '555111', 'ean' => '3017620422003', 'label' => 'Farine T55 1 kg',
                    'content' => ['value' => 1000, 'unit' => 'g'],
                    'price' => ['cents' => 149, 'perMeasure' => ['cents' => 149, 'unit' => 'kg']],
                ]],
                ['ingredientSlug' => 'lait', 'action' => 'ADD_PRODUCT', 'placement' => 'FALLBACK', 'product' => ['retailerProductId' => '444222', 'label' => 'Lait demi-écrémé 1 l']],
                ['ingredientSlug' => 'lait', 'action' => 'REJECT_PRODUCT', 'product' => ['retailerProductId' => '98765', 'label' => 'Lait UHT 1 l', 'content' => ['value' => 0.5, 'unit' => 'l']]],
                ['ingredientSlug' => 'sel', 'action' => 'HAVE_AT_HOME'],
                ['ingredientSlug' => 'farine', 'action' => 'REFRESH_PRICE', 'product' => ['retailerProductId' => '555111', 'price' => ['cents' => 159]]],
            ],
        ]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new SaveProductChoicesCommand($user->getId(), Retailer::LECLERC, '123456', [
            new ChoiceIntent('farine', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput(
                '555111',
                'Farine T55 1 kg',
                '3017620422003',
                new ProductContent(1000, ContentUnit::G),
                new ProductPrice(149, new PricePerMeasure(149, PriceUnit::KG)),
            )),
            new ChoiceIntent('lait', ChoiceAction::ADD_PRODUCT, Placement::FALLBACK, new ProductInput('444222', 'Lait demi-écrémé 1 l')),
            new ChoiceIntent('lait', ChoiceAction::REJECT_PRODUCT, null, new ProductInput('98765', 'Lait UHT 1 l', null, new ProductContent(0.5, ContentUnit::L))),
            new ChoiceIntent('sel', ChoiceAction::HAVE_AT_HOME),
            new ChoiceIntent('farine', ChoiceAction::REFRESH_PRICE, null, new ProductInput('555111', null, null, null, new ProductPrice(159))),
        ])], $commandBus->dispatched());
    }

    public function testTheRayonAndTheFamilyOfAProductAreFacultative(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When : l'agent récent donne le rayon et la famille, un autre seulement le rayon, un autre `null`, un ancien rien du tout
        $this->put($user, ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'farine', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '1', 'label' => 'Farine', 'rayonId' => 4001, 'familyId' => 4101]],
            ['ingredientSlug' => 'lait', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '2', 'label' => 'Lait', 'rayonId' => 2147483647]],
            ['ingredientSlug' => 'sel', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '3', 'label' => 'Sel', 'rayonId' => null, 'familyId' => null]],
            ['ingredientSlug' => 'sucre', 'action' => 'REJECT_PRODUCT', 'product' => ['retailerProductId' => '4', 'label' => 'Sucre']],
        ]]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new SaveProductChoicesCommand($user->getId(), Retailer::LECLERC, '123456', [
            new ChoiceIntent('farine', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('1', 'Farine', rayonId: 4001, familyId: 4101)),
            new ChoiceIntent('lait', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('2', 'Lait', rayonId: 2147483647)),
            new ChoiceIntent('sel', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('3', 'Sel')),
            new ChoiceIntent('sucre', ChoiceAction::REJECT_PRODUCT, null, new ProductInput('4', 'Sucre')),
        ])], $commandBus->dispatched());
    }

    public function testTheOfferIdOfAProductIsFacultativeAndForwardedToTheCommand(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();
        $offerId = 'c3b2a1d0-5e4f-4a6b-9c8d-7e6f5a4b3c2d';

        // When : un agent qui rapporte l'offre, un autre `null`, un ancien qui ne connaît pas le champ
        $this->put($user, ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'farine', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '1', 'label' => 'Farine', 'offerId' => $offerId]],
            ['ingredientSlug' => 'lait', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '2', 'label' => 'Lait', 'offerId' => null]],
            ['ingredientSlug' => 'sel', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '3', 'label' => 'Sel']],
        ]]);

        // Then : transmis tel quel (c'est le cas d'usage qui juge la forme, d'après l'enseigne)
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new SaveProductChoicesCommand($user->getId(), Retailer::LECLERC, '123456', [
            new ChoiceIntent('farine', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('1', 'Farine', offerId: $offerId)),
            new ChoiceIntent('lait', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('2', 'Lait')),
            new ChoiceIntent('sel', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('3', 'Sel')),
        ])], $commandBus->dispatched());
    }

    public function testAnOfferIdLongerThanTheColumnIsRefused(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(), ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'farine', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '1', 'label' => 'Farine', 'offerId' => str_repeat('a', 41)]],
        ]]);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testTheSearchQueryIsKeptWhenAbsentForgottenWhenNullAndReplacedWhenText(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();
        $product = static fn(string $id): array => ['retailerProductId' => $id, 'label' => 'Céréales coussins'];

        // When : une application qui ne connaît pas le champ, `null` explicite, un texte (à normaliser), un refus sans le champ
        $this->put($user, ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'farine', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => $product('1')],
            ['ingredientSlug' => 'lait', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => $product('2'), 'searchQuery' => null],
            ['ingredientSlug' => 'cereale', 'action' => 'ADD_PRODUCT', 'placement' => 'FALLBACK', 'product' => $product('3'), 'searchQuery' => '  céréales   coussins '],
            ['ingredientSlug' => 'sucre', 'action' => 'REJECT_PRODUCT', 'product' => $product('4')],
            ['ingredientSlug' => 'sel', 'action' => 'HAVE_AT_HOME'],
        ]]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new SaveProductChoicesCommand($user->getId(), Retailer::LECLERC, '123456', [
            new ChoiceIntent('farine', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('1', 'Céréales coussins')),
            new ChoiceIntent('lait', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('2', 'Céréales coussins'), SearchQueryChange::clear()),
            new ChoiceIntent('cereale', ChoiceAction::ADD_PRODUCT, Placement::FALLBACK, new ProductInput('3', 'Céréales coussins'), SearchQueryChange::set(SearchQuery::of('céréales coussins'))),
            new ChoiceIntent('sucre', ChoiceAction::REJECT_PRODUCT, null, new ProductInput('4', 'Céréales coussins')),
            new ChoiceIntent('sel', ChoiceAction::HAVE_AT_HOME),
        ])], $commandBus->dispatched());
        $intents = $commandBus->dispatched()[0]->intents;
        $this->assertNull($intents[0]->searchQuery);
        $this->assertNull($intents[1]->searchQuery->searchQuery);
        $this->assertSame('céréales coussins', $intents[2]->searchQuery->searchQuery->value);
    }

    /**
     * Une recherche mal formée est refusée avec son propre code (le client sait que c'est ce texte qu'il doit corriger), et rien
     * n'est enregistré, même pour les autres intentions du lot.
     */
    #[DataProvider('invalidSearchQueries')]
    public function testRefusesAnInvalidSearchQueryWithItsOwnMessage(mixed $searchQuery): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(), ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'sel', 'action' => 'HAVE_AT_HOME'],
            ['ingredientSlug' => 'cereale', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '1', 'label' => 'Céréales'], 'searchQuery' => $searchQuery],
        ]]);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_SEARCH_QUERY_INVALID']], $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidSearchQueries(): iterable
    {
        yield 'texte vide' => [''];
        yield 'espaces seulement' => ['   '];
        yield 'un seul caractère' => ['c'];
        yield 'plus de 80 caractères' => [str_repeat('a', 81)];
        yield 'saut de ligne' => ["céréales\ncoussins"];
        yield 'espace de largeur nulle' => ["céréales\u{200B}coussins"];
    }

    /**
     * @param mixed $searchQuery
     */
    #[DataProvider('searchQueriesThatAreNotText')]
    public function testRefusesASearchQueryThatIsNotATextWithoutSavingAnything(mixed $searchQuery): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(), ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'cereale', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '1', 'label' => 'Céréales'], 'searchQuery' => $searchQuery],
        ]]);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function searchQueriesThatAreNotText(): iterable
    {
        yield 'un nombre' => [12];
        yield 'un booléen' => [true];
        yield 'une liste' => [['céréales coussins']];
        yield 'un objet' => [['texte' => 'céréales coussins']];
    }

    public function testASearchQueryOnAnotherActionIsPassedOnAndTheHandlerRefusesIt(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(), ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'sel', 'action' => 'HAVE_AT_HOME', 'searchQuery' => 'gros sel'],
        ]]);

        // Then : le controller ne juge pas la cohérence de l'intention, c'est le gestionnaire qui la refuse (parcours : DriveChoicesApiFlowTest)
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('gros sel', $commandBus->dispatched()[0]->intents[0]->searchQuery->searchQuery->value);
    }

    public function testNoteSearchIsPassedOnWithItsNormalizedSearchAndNoProduct(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When : le produit n'est pas mémorisé, seule la recherche l'est ; une intention sans clé de recherche et une à `null` passent aussi
        // au gestionnaire, qui les refuse (parcours : DriveChoicesApiFlowTest)
        $this->put($user, ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'cereale', 'action' => 'NOTE_SEARCH', 'searchQuery' => '  céréales   coussins '],
            ['ingredientSlug' => 'lait', 'action' => 'NOTE_SEARCH'],
            ['ingredientSlug' => 'sel', 'action' => 'NOTE_SEARCH', 'searchQuery' => null],
        ]]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new SaveProductChoicesCommand($user->getId(), Retailer::LECLERC, '123456', [
            new ChoiceIntent('cereale', ChoiceAction::NOTE_SEARCH, null, null, SearchQueryChange::set(SearchQuery::of('céréales coussins'))),
            new ChoiceIntent('lait', ChoiceAction::NOTE_SEARCH),
            new ChoiceIntent('sel', ChoiceAction::NOTE_SEARCH, null, null, SearchQueryChange::clear()),
        ])], $commandBus->dispatched());
    }

    #[DataProvider('invalidSearchQueries')]
    public function testRefusesAnInvalidSearchQueryOfNoteSearchWithItsOwnMessage(mixed $searchQuery): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(), ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'cereale', 'action' => 'NOTE_SEARCH', 'searchQuery' => $searchQuery],
        ]]);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_SEARCH_QUERY_INVALID']], $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testThePackSizeIsKeptWhenAbsentForgottenWhenNullAndReplacedWhenAnObject(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();
        $product = static fn(string $id, array $more = []): array => ['retailerProductId' => $id, 'label' => 'Fromage Cheddar Burger 20 Tranches - 340g', ...$more];

        // When : une application qui ne connaît pas le champ, `null` explicite, un objet, un entier dans `value`, une décimale
        $this->put($user, ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'cheddar', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => $product('1')],
            ['ingredientSlug' => 'emmental', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => $product('2', ['packSize' => null])],
            ['ingredientSlug' => 'comte', 'action' => 'ADD_PRODUCT', 'placement' => 'FALLBACK', 'product' => $product('3', ['packSize' => ['value' => 20, 'unitSlug' => 'tranche']])],
            ['ingredientSlug' => 'beurre', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => $product('4', ['packSize' => ['value' => 12.5, 'unitSlug' => 'cuillere-a-soupe']])],
            ['ingredientSlug' => 'sel', 'action' => 'HAVE_AT_HOME'],
        ]]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $label = 'Fromage Cheddar Burger 20 Tranches - 340g';
        $this->assertEquals([new SaveProductChoicesCommand($user->getId(), Retailer::LECLERC, '123456', [
            new ChoiceIntent('cheddar', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('1', $label)),
            new ChoiceIntent('emmental', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('2', $label, packSize: PackSizeChange::clear())),
            new ChoiceIntent('comte', ChoiceAction::ADD_PRODUCT, Placement::FALLBACK, new ProductInput('3', $label, packSize: PackSizeChange::set(PackSize::of(20, 'tranche')))),
            new ChoiceIntent('beurre', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('4', $label, packSize: PackSizeChange::set(PackSize::of(12.5, 'cuillere-a-soupe')))),
            new ChoiceIntent('sel', ChoiceAction::HAVE_AT_HOME),
        ])], $commandBus->dispatched());
        $intents = $commandBus->dispatched()[0]->intents;
        $this->assertNull($intents[0]->product->packSize);
        $this->assertNull($intents[1]->product->packSize->packSize);
        $this->assertSame(['tranche', 20.0], [$intents[2]->product->packSize->packSize->unitSlug, $intents[2]->product->packSize->packSize->value]);
    }

    public function testThePackSizeOfEachProductOfAChoiceIsReadFromItsOwnProductObject(): void
    {
        // Given : deux produits du même ingrédient, la clé n'est présente que sur le second
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(), ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'cheddar', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '1', 'label' => 'Cheddar A']],
            ['ingredientSlug' => 'cheddar', 'action' => 'ADD_PRODUCT', 'placement' => 'FALLBACK', 'product' => ['retailerProductId' => '2', 'label' => 'Cheddar B', 'packSize' => ['value' => 8, 'unitSlug' => 'tranche']]],
        ]]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $intents = $commandBus->dispatched()[0]->intents;
        $this->assertNull($intents[0]->product->packSize);
        $this->assertSame(8.0, $intents[1]->product->packSize->packSize->value);
    }

    /**
     * Une contenance mal formée est refusée avec son propre code (le client sait que c'est cette réponse qu'il doit corriger), et rien
     * n'est enregistré, même pour les autres intentions du lot.
     */
    #[DataProvider('invalidPackSizes')]
    public function testRefusesAnInvalidPackSizeWithItsOwnMessage(mixed $packSize): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(), ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'sel', 'action' => 'HAVE_AT_HOME'],
            ['ingredientSlug' => 'cheddar', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '1', 'label' => 'Cheddar', 'packSize' => $packSize]],
        ]]);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_PACK_SIZE_INVALID']], $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidPackSizes(): iterable
    {
        yield 'zéro' => [['value' => 0, 'unitSlug' => 'tranche']];
        yield 'négatif' => [['value' => -20, 'unitSlug' => 'tranche']];
        yield 'plus de 9 999' => [['value' => 10000, 'unitSlug' => 'tranche']];
        yield 'quatre décimales' => [['value' => 1.2345, 'unitSlug' => 'tranche']];
        yield 'sous le millième' => [['value' => 0.0001, 'unitSlug' => 'tranche']];
        yield 'unité vide' => [['value' => 20, 'unitSlug' => '']];
        yield 'unité avec une majuscule' => [['value' => 20, 'unitSlug' => 'Tranche']];
        yield 'unité avec une espace' => [['value' => 20, 'unitSlug' => 'tranche fine']];
        yield 'unité avec un saut de ligne' => [['value' => 20, 'unitSlug' => "tranche\n"]];
        yield 'unité de 256 caractères' => [['value' => 20, 'unitSlug' => str_repeat('a', 256)]];
    }

    /**
     * @param mixed $packSize
     */
    #[DataProvider('packSizesThatAreNotObjects')]
    public function testRefusesAPackSizeThatIsNotAWellTypedObjectWithoutSavingAnything(mixed $packSize): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(), ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'cheddar', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '1', 'label' => 'Cheddar', 'packSize' => $packSize]],
        ]]);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function packSizesThatAreNotObjects(): iterable
    {
        yield 'un nombre' => [20];
        yield 'un texte' => ['20 tranches'];
        yield 'un booléen' => [true];
        yield 'un objet sans valeur' => [['unitSlug' => 'tranche']];
        yield 'un objet sans unité' => [['value' => 20]];
        yield 'une valeur textuelle' => [['value' => 'vingt', 'unitSlug' => 'tranche']];
        yield 'une unité qui n\'est pas un texte' => [['value' => 20, 'unitSlug' => 7]];
        yield 'une unité nulle' => [['value' => 20, 'unitSlug' => null]];
    }

    public function testAPackSizeOnAnotherActionIsPassedOnAndTheHandlerRefusesIt(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(), ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'cheddar', 'action' => 'REJECT_PRODUCT', 'product' => ['retailerProductId' => '1', 'label' => 'Cheddar', 'packSize' => ['value' => 20, 'unitSlug' => 'tranche']]],
        ]]);

        // Then : le controller ne juge pas la cohérence de l'intention, c'est le gestionnaire qui la refuse (parcours : DriveChoicesApiFlowTest)
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(20.0, $commandBus->dispatched()[0]->intents[0]->product->packSize->packSize->value);
    }

    public function testAnEmptyBatchIsAccepted(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($user, ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => []]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new SaveProductChoicesCommand($user->getId(), Retailer::LECLERC, '123456', [])], $commandBus->dispatched());
    }

    public function testTheUserComesFromTheTokenNeverFromTheBody(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($user, ['userId' => $other->getId(), 'retailer' => 'leclerc', 'storeId' => '123456', 'choices' => []]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame($user->getId(), $commandBus->dispatched()[0]->userId);
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testRefusesAnInvalidBody(array $body): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(), $body);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        $batch = static fn(array ...$choices): array => ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => $choices];
        $add = ['ingredientSlug' => 'farine', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '1', 'label' => 'Farine']];

        yield 'corps vide' => [[]];
        yield 'sans enseigne' => [['storeId' => '123456', 'choices' => []]];
        yield 'enseigne inconnue' => [['retailer' => 'inconnue', 'storeId' => '123456', 'choices' => []]];
        yield 'sans magasin' => [['retailer' => 'leclerc', 'choices' => []]];
        yield 'magasin vide' => [['retailer' => 'leclerc', 'storeId' => '', 'choices' => []]];
        yield 'magasin de plus de 40 caractères' => [['retailer' => 'leclerc', 'storeId' => str_repeat('1', 41), 'choices' => []]];
        yield 'sans lot' => [['retailer' => 'leclerc', 'storeId' => '123456']];
        yield 'lot qui n\'est pas une liste' => [['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => 'farine']];
        yield 'plus de 200 intentions' => [['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => array_fill(0, 201, ['ingredientSlug' => 'sel', 'action' => 'HAVE_AT_HOME'])]];
        yield 'action inconnue' => [$batch(['ingredientSlug' => 'farine', 'action' => 'DELETE_EVERYTHING'])];
        yield 'sans action' => [$batch(['ingredientSlug' => 'farine'])];
        yield 'sans ingrédient' => [$batch(['action' => 'HAVE_AT_HOME'])];
        yield 'ingrédient vide' => [$batch(['ingredientSlug' => '', 'action' => 'HAVE_AT_HOME'])];
        yield 'placement inconnu' => [$batch([...$add, 'placement' => 'ONCE'])];
        yield 'produit qui n\'est pas un objet' => [$batch([...$add, 'product' => '555'])];
        yield 'produit sans identifiant' => [$batch([...$add, 'product' => ['label' => 'Farine']])];
        yield 'identifiant de produit numérique' => [$batch([...$add, 'product' => ['retailerProductId' => 555111, 'label' => 'Farine']])];
        yield 'libellé de plus de 255 caractères' => [$batch([...$add, 'product' => ['retailerProductId' => '1', 'label' => str_repeat('a', 256)]])];
        yield 'contenu sans unité' => [$batch([...$add, 'product' => ['retailerProductId' => '1', 'label' => 'Farine', 'content' => ['value' => 5]]])];
        yield 'unité de contenu inconnue' => [$batch([...$add, 'product' => ['retailerProductId' => '1', 'label' => 'Farine', 'content' => ['value' => 5, 'unit' => 'lb']]])];
        yield 'contenu qui n\'est pas un nombre' => [$batch([...$add, 'product' => ['retailerProductId' => '1', 'label' => 'Farine', 'content' => ['value' => 'beaucoup', 'unit' => 'g']]])];
        yield 'prix sans centimes' => [$batch([...$add, 'product' => ['retailerProductId' => '1', 'label' => 'Farine', 'price' => []]])];
        yield 'prix qui n\'est pas un nombre' => [$batch([...$add, 'product' => ['retailerProductId' => '1', 'label' => 'Farine', 'price' => ['cents' => 'gratuit']]])];
        yield 'rayon à zéro' => [$batch([...$add, 'product' => ['retailerProductId' => '1', 'label' => 'Farine', 'rayonId' => 0]])];
        yield 'rayon négatif' => [$batch([...$add, 'product' => ['retailerProductId' => '1', 'label' => 'Farine', 'rayonId' => -4001]])];
        yield 'rayon trop grand pour un INT' => [$batch([...$add, 'product' => ['retailerProductId' => '1', 'label' => 'Farine', 'rayonId' => 2147483648]])];
        yield 'rayon en texte' => [$batch([...$add, 'product' => ['retailerProductId' => '1', 'label' => 'Farine', 'rayonId' => 'fruits']])];
        yield 'rayon en chaîne de chiffres' => [$batch([...$add, 'product' => ['retailerProductId' => '1', 'label' => 'Farine', 'rayonId' => '4001']])];
        yield 'famille à zéro' => [$batch([...$add, 'product' => ['retailerProductId' => '1', 'label' => 'Farine', 'familyId' => 0]])];
        yield 'famille trop grande pour un INT' => [$batch([...$add, 'product' => ['retailerProductId' => '1', 'label' => 'Farine', 'familyId' => 2147483648]])];
        yield 'prix à l\'unité de mesure inconnue' => [$batch([...$add, 'product' => ['retailerProductId' => '1', 'label' => 'Farine', 'price' => ['cents' => 149, 'perMeasure' => ['cents' => 149, 'unit' => 'lb']]]])];
    }

    /**
     * Les valeurs hors bornes sont refusées par le domaine, avec le message métier.
     *
     * @param array<string, mixed> $product
     */
    #[DataProvider('productsOutOfBounds')]
    public function testRefusesAPriceOutOfBoundsWithTheBusinessMessage(array $product): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(), ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [['ingredientSlug' => 'farine', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => $product + ['retailerProductId' => '1', 'label' => 'Farine']]]]);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_CHOICE_INVALID']], $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function productsOutOfBounds(): iterable
    {
        yield 'prix négatif' => [['price' => ['cents' => -1]]];
        yield 'prix démesuré' => [['price' => ['cents' => 1_000_001]]];
        yield 'prix à l\'unité de mesure négatif' => [['price' => ['cents' => 10, 'perMeasure' => ['cents' => -5, 'unit' => 'kg']]]];
    }

    public function testAnswers404DriveDisabledWhenTheSwitchIsOffEvenWithAnInvalidBody(): void
    {
        // Given
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(), []);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_DISABLED']], $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('PUT', '/api/v1/drive/choices', content: '{}', server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(User $user, array $body): void
    {
        $this->client->request('PUT', '/api/v1/drive/choices', content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
