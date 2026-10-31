<?php

namespace Repas\Tests\Drive\InputAdapter;


use Repas\Drive\Application\Command\ForgetChoice\ForgetChoiceCommand;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Interface\DriveFeature;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ForgetChoiceApiControllerTest extends QueryBusWebTestCase
{
    public function testTheConnectedUserForgetsHisChoice(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/drive/choices/farine?retailer=leclerc&storeId=123456', server: $this->bearerFor($user));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new ForgetChoiceCommand($user->getId(), Retailer::LECLERC, '123456', 'farine')], $commandBus->dispatched());
    }

    public function testRefusesMissingOrInvalidParameters(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When / Then
        foreach (['', '?retailer=leclerc', '?storeId=123456', '?retailer=inconnue&storeId=123456', '?retailer=leclerc&storeId=', '?retailer=leclerc&storeId=' . str_repeat('1', 41)] as $query) {
            $this->client->request('DELETE', '/api/v1/drive/choices/farine' . $query, server: $this->bearerFor($user));
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $query);
        }
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnIngredientThatIsNotASlugIsNotARoute(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/drive/choices/Farine%20Bio?retailer=leclerc&storeId=123456', server: $this->bearerFor($this->savedUser()));

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
        $this->client->request('DELETE', '/api/v1/drive/choices/farine', server: $this->bearerFor($this->savedUser()));

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
        $this->client->request('DELETE', '/api/v1/drive/choices/farine?retailer=leclerc&storeId=123456');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }
}
