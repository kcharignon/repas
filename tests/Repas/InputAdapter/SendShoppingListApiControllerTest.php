<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\SendShoppingList\SendShoppingListCommand;
use Repas\Shared\Application\Interface\DriveFeature;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\QueryBusWebTestCase;

class SendShoppingListApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheCommandAndReturnsNoContent(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $shoppingList = $this->savedShoppingList($owner);

        // When
        $this->client->request('POST', '/api/v1/shopping-lists/' . $shoppingList->getId() . '/send', server: $this->bearerFor($owner));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new SendShoppingListCommand($shoppingList->getId())], $commandBus->dispatched());
    }

    public function testForwardsTheIfMatchVersionToTheCommand(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $shoppingList = $this->savedShoppingList($owner);

        // When
        $this->client->request('POST', '/api/v1/shopping-lists/' . $shoppingList->getId() . '/send', server: $this->bearerFor($owner) + ['HTTP_IF_MATCH' => '"6"']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new SendShoppingListCommand($shoppingList->getId(), 6)], $commandBus->dispatched());
    }

    public function testRefusesAnIfMatchThatIsNotAnETagOfThisApi(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $shoppingList = $this->savedShoppingList($owner);

        // When
        $this->client->request('POST', '/api/v1/shopping-lists/' . $shoppingList->getId() . '/send', server: $this->bearerFor($owner) + ['HTTP_IF_MATCH' => 'abc']);

        // Then
        $this->assertSame(412, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAResourceOfAnotherUser(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $shoppingList = $this->savedShoppingList($this->savedUser());

        // When
        $this->client->request('POST', '/api/v1/shopping-lists/' . $shoppingList->getId() . '/send', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testReturns404ForAnUnknownList(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/shopping-lists/unknown-list/send', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/shopping-lists/unknown-list/send');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnswers404DriveDisabledWhenTheSwitchIsOff(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));
        $owner = $this->savedUser();
        $shoppingList = $this->savedShoppingList($owner);

        // When
        $this->client->request('POST', '/api/v1/shopping-lists/' . $shoppingList->getId() . '/send', server: $this->bearerFor($owner));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_DISABLED']], $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnAnonymousCallStillGets401WhenTheSwitchIsOff(): void
    {
        // Given
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));

        // When
        $this->client->request('POST', '/api/v1/shopping-lists/unknown-list/send');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }
}
