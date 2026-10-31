<?php

namespace Repas\Tests\Voice\InputAdapter;


use DateTimeImmutable;
use Repas\Voice\Domain\Interface\VoiceFeature;
use Repas\Tests\Helper\ControlledVoiceFeature;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\Voice\Application\Command\RevokeVoiceLink\RevokeVoiceLinkCommand;
use Repas\Voice\Domain\Interface\VoiceLinkRepository;
use Repas\Voice\Domain\Model\VoiceLink;

class RevokeVoiceLinkApiControllerTest extends QueryBusWebTestCase
{
    public function testTheOwnerRevokesHisLink(): void
    {
        // Given
        $user = $this->savedUser();
        $link = $this->savedLink($user->getId());
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/voice/links/' . $link->getId(), server: $this->bearerFor($user));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new RevokeVoiceLinkCommand($user->getId(), $link->getId())], $commandBus->dispatched());
    }

    public function testAnotherUsersLinkAnswers403AndNothingIsDispatched(): void
    {
        // Given
        $owner = $this->savedUser();
        $other = $this->savedUser();
        $link = $this->savedLink($owner->getId());
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/voice/links/' . $link->getId(), server: $this->bearerFor($other));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnAdministratorHasNoPassThrough(): void
    {
        // Given
        $owner = $this->savedUser();
        $admin = $this->savedUser(admin: true);
        $link = $this->savedLink($owner->getId());
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/voice/links/' . $link->getId(), server: $this->bearerFor($admin));

        // Then : retirer la liaison d'un autre couperait sa commande vocale
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnUnknownLinkAnswers404(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/voice/links/00000000-0000-4000-8000-000000000000', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnIdentifierThatIsNotAnIdIsNotARoute(): void
    {
        $commandBus = $this->mockCommandBus();

        $this->client->request('DELETE', '/api/v1/voice/links/' . str_repeat('a', 37), server: $this->bearerFor($this->savedUser()));

        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnAnonymousCallAnswers401(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/voice/links/anything');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnswers404VoiceDisabledWhenTheSwitchIsOff(): void
    {
        // Given
        $user = $this->savedUser();
        $link = $this->savedLink($user->getId());
        static::getContainer()->set(VoiceFeature::class, new ControlledVoiceFeature(false));
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/voice/links/' . $link->getId(), server: $this->bearerFor($user));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'VOICE_DISABLED']], $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    private function savedLink(string $userId): VoiceLink
    {
        $link = VoiceLink::create(\Repas\Shared\Domain\Tool\UuidGenerator::new(), $userId, new DateTimeImmutable('2026-10-25 12:00:00'));
        static::getContainer()->get(VoiceLinkRepository::class)->save($link);

        return $link;
    }
}
