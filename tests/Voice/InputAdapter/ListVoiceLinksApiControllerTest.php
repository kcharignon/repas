<?php

namespace Repas\Tests\Voice\InputAdapter;


use Repas\Shared\Application\Interface\VoiceFeature;
use Repas\Tests\Helper\ControlledVoiceFeature;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\Voice\Application\Query\ListVoiceLinks\ListVoiceLinksQuery;
use Repas\Voice\Application\Query\ListVoiceLinks\VoiceLinkDTO;

class ListVoiceLinksApiControllerTest extends QueryBusWebTestCase
{
    private const string URL = '/api/v1/voice/links';

    public function testAsksTheQueryForTheAuthenticatedUserAndReturnsTheDTOKeys(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListVoiceLinksQuery::class, [
            new VoiceLinkDTO('link-new', 'alexa', '2026-10-25T12:00:00+01:00', '2026-10-25T13:30:00+01:00'),
            new VoiceLinkDTO('link-old', 'alexa', '2026-10-20T10:00:00+01:00', null),
        ]);

        // When
        $this->client->request('GET', self::URL, server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(new ListVoiceLinksQuery($user->getId()), $this->queryBus->getLastAskedQuery());
        $this->assertSame([
            'member' => [
                ['id' => 'link-new', 'provider' => 'alexa', 'createdAt' => '2026-10-25T12:00:00+01:00', 'lastUsedAt' => '2026-10-25T13:30:00+01:00'],
                ['id' => 'link-old', 'provider' => 'alexa', 'createdAt' => '2026-10-20T10:00:00+01:00', 'lastUsedAt' => null],
            ],
            'totalItems' => 2,
        ], $this->jsonBody());
    }

    public function testAnEmptyListIsAnEmptyMember(): void
    {
        // Given
        $this->queryBus->willReturn(ListVoiceLinksQuery::class, []);

        // When
        $this->client->request('GET', self::URL, server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testAnAnonymousCallAnswers401(): void
    {
        $this->client->request('GET', self::URL);

        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testAnswers404VoiceDisabledWhenTheSwitchIsOff(): void
    {
        // Given
        static::getContainer()->set(VoiceFeature::class, new ControlledVoiceFeature(false));

        // When
        $this->client->request('GET', self::URL, server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'VOICE_DISABLED']], $this->jsonBody());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
