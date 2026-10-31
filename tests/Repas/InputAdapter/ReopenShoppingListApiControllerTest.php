<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\ReopenShoppingList\ReopenShoppingListCommand;
use Repas\Drive\Domain\Interface\DriveFeature;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ReopenShoppingListApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheCommandAndReturnsNoContent(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $shoppingList = $this->savedShoppingList($owner);

        // When
        $this->client->request('POST', '/api/v1/shopping-lists/' . $shoppingList->getId() . '/reopen', server: $this->bearerFor($owner));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new ReopenShoppingListCommand($shoppingList->getId())], $commandBus->dispatched());
    }

    public function testForwardsTheIfMatchVersionToTheCommand(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $shoppingList = $this->savedShoppingList($owner);

        // When
        $this->client->request('POST', '/api/v1/shopping-lists/' . $shoppingList->getId() . '/reopen', server: $this->bearerFor($owner) + ['HTTP_IF_MATCH' => '"6"']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new ReopenShoppingListCommand($shoppingList->getId(), 6)], $commandBus->dispatched());
    }

    public function testRefusesAnIfMatchThatIsNotAnETagOfThisApi(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $shoppingList = $this->savedShoppingList($owner);

        // When
        $this->client->request('POST', '/api/v1/shopping-lists/' . $shoppingList->getId() . '/reopen', server: $this->bearerFor($owner) + ['HTTP_IF_MATCH' => 'abc']);

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
        $this->client->request('POST', '/api/v1/shopping-lists/' . $shoppingList->getId() . '/reopen', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testReturns404ForAnUnknownList(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/shopping-lists/unknown-list/reopen', server: $this->bearerFor($this->savedUser()));

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
        $this->client->request('POST', '/api/v1/shopping-lists/unknown-list/reopen');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * DRV-613 (constat C3) : rouvrir une liste ne dépend pas de l'interrupteur du drive, une coupure d'urgence ne fige pas les listes.
     */
    public function testStillAnswers204WhenTheSwitchIsOff(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));
        $owner = $this->savedUser();
        $shoppingList = $this->savedShoppingList($owner);

        // When
        $this->client->request('POST', '/api/v1/shopping-lists/' . $shoppingList->getId() . '/reopen', server: $this->bearerFor($owner));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new ReopenShoppingListCommand($shoppingList->getId())], $commandBus->dispatched());
    }

    public function testTheOwnerRuleStillAppliesWhenTheSwitchIsOff(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));
        $shoppingList = $this->savedShoppingList($this->savedUser());

        // When : la liste d'un autre, puis une liste inconnue
        $this->client->request('POST', '/api/v1/shopping-lists/' . $shoppingList->getId() . '/reopen', server: $this->bearerFor($this->savedUser()));
        $other = $this->client->getResponse()->getStatusCode();
        $this->client->request('POST', '/api/v1/shopping-lists/unknown-list/reopen', server: $this->bearerFor($this->savedUser()));

        // Then : 403 et 404 de la liste (jamais DRIVE_DISABLED), rien n'est envoyé au bus
        $this->assertSame([403, 404], [$other, $this->client->getResponse()->getStatusCode()]);
        $this->assertStringNotContainsString('DRIVE_DISABLED', $this->client->getResponse()->getContent());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnAnonymousCallStillGets401WhenTheSwitchIsOff(): void
    {
        // Given
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));

        // When
        $this->client->request('POST', '/api/v1/shopping-lists/unknown-list/reopen');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }
}
