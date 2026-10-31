<?php

namespace Repas\Tests\Drive\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Drive\Application\Command\ReplaceIngredientPreference\ReplaceIngredientPreferenceCommand;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Interface\DriveFeature;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class ReplaceIngredientPreferenceApiControllerTest extends QueryBusWebTestCase
{
    public function testTheConnectedUserReplacesHisPreference(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->put('farine', ['retailer' => 'leclerc', 'storeId' => '123456', 'haveAtHome' => false, 'accepted' => ['p-b', 'p-a'], 'rejected' => ['p-x']], $user);

        // Then : l'utilisateur vient du jeton, l'ingrédient de l'adresse
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(
            [new ReplaceIngredientPreferenceCommand($user->getId(), Retailer::LECLERC, '123456', 'farine', false, ['p-b', 'p-a'], ['p-x'])],
            $commandBus->dispatched(),
        );
    }

    public function testHaveAtHomeWithoutProducts(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->put('sel', ['retailer' => 'leclerc', 'storeId' => '123456', 'haveAtHome' => true, 'accepted' => [], 'rejected' => []], $user);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new ReplaceIngredientPreferenceCommand($user->getId(), Retailer::LECLERC, '123456', 'sel', true, [], [])], $commandBus->dispatched());
    }

    public function testTheUserOfTheBodyIsIgnored(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->put('farine', ['userId' => 'un-autre', 'retailer' => 'leclerc', 'storeId' => '123456', 'haveAtHome' => false, 'accepted' => [], 'rejected' => []], $user);

        // Then
        $this->assertSame($user->getId(), $commandBus->dispatched()[0]->userId);
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testRefusesAnInvalidBody(array $body): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->put('farine', $body, $user);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public static function invalidBodies(): iterable
    {
        $valid = ['retailer' => 'leclerc', 'storeId' => '123456', 'haveAtHome' => false, 'accepted' => [], 'rejected' => []];
        foreach (['retailer', 'storeId', 'haveAtHome', 'accepted', 'rejected'] as $field) {
            yield "sans $field" => [array_diff_key($valid, [$field => true])];
        }
        yield 'enseigne inconnue' => [['retailer' => 'inconnue'] + $valid];
        yield 'magasin vide' => [['storeId' => ''] + $valid];
        yield 'magasin de plus de 40 caractères' => [['storeId' => str_repeat('1', 41)] + $valid];
        yield 'haveAtHome pas un booléen' => [['haveAtHome' => 'oui'] + $valid];
        yield 'identifiant pas une chaîne' => [['accepted' => [12]] + $valid];
        yield 'identifiant vide' => [['rejected' => ['']] + $valid];
        yield 'identifiant trop long' => [['accepted' => [str_repeat('a', 37)]] + $valid];
    }

    public function testAnIngredientThatIsNotASlugIsNotARoute(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put('Farine%20Bio', ['retailer' => 'leclerc', 'storeId' => '123456', 'haveAtHome' => false, 'accepted' => [], 'rejected' => []], $this->savedUser());

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnswers404DriveDisabledWhenTheSwitchIsOff(): void
    {
        // Given
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));
        $commandBus = $this->mockCommandBus();

        // When
        $this->put('farine', [], $this->savedUser());

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
        $this->client->request('PUT', '/api/v1/drive/choices/farine', content: '{}');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(string $slug, array $body, User $user): void
    {
        $this->client->request('PUT', "/api/v1/drive/choices/$slug", content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
