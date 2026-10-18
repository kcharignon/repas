<?php

namespace Repas\Tests\Drive\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Drive\Application\Command\SaveProductChoices\ChoiceAction;
use Repas\Drive\Application\Command\SaveProductChoices\ChoiceIntent;
use Repas\Drive\Application\Command\SaveProductChoices\Placement;
use Repas\Drive\Application\Command\SaveProductChoices\ProductInput;
use Repas\Drive\Application\Command\SaveProductChoices\SaveProductChoicesCommand;
use Repas\Drive\Domain\Model\ContentUnit;
use Repas\Drive\Domain\Model\PricePerMeasure;
use Repas\Drive\Domain\Model\PriceUnit;
use Repas\Drive\Domain\Model\ProductContent;
use Repas\Drive\Domain\Model\ProductPrice;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Application\Interface\DriveFeature;
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
        yield 'enseigne inconnue' => [['retailer' => 'auchan', 'storeId' => '123456', 'choices' => []]];
        yield 'sans magasin' => [['retailer' => 'leclerc', 'choices' => []]];
        yield 'magasin vide' => [['retailer' => 'leclerc', 'storeId' => '', 'choices' => []]];
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
