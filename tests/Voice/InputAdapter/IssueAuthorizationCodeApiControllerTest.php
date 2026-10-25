<?php

namespace Repas\Tests\Voice\InputAdapter;


use Repas\Shared\Application\Interface\VoiceFeature;
use Repas\Tests\Helper\Alexa\AlexaTestClient;
use Repas\Tests\Helper\ControlledVoiceFeature;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\Voice\Application\Command\IssueAuthorizationCode\IssueAuthorizationCodeCommand;
use Repas\Voice\Domain\Exception\VoiceException;

class IssueAuthorizationCodeApiControllerTest extends QueryBusWebTestCase
{
    private const string URL = '/api/v1/voice/alexa/authorize';

    public function testTheConnectedUserAuthorizesAlexaAndGetsTheAddressToGoBackTo(): void
    {
        // Given
        $user = $this->savedUser();
        $pkce = AlexaTestClient::pkce();
        $redirectUrl = AlexaTestClient::REDIRECT_URI . '?state=xyz123&code=the-code';
        $commandBus = $this->mockCommandBus()->willReturn(IssueAuthorizationCodeCommand::class, $redirectUrl);

        // When
        $this->post($this->body($pkce['challenge']), $user);

        // Then : l'utilisateur vient du JWT ; la réponse n'a que `redirectUrl` et ne se met jamais en cache
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['redirectUrl' => $redirectUrl], $this->jsonBody());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertEquals([new IssueAuthorizationCodeCommand($user->getId(), AlexaTestClient::CLIENT_ID, AlexaTestClient::REDIRECT_URI, 'xyz123', $pkce['challenge'], 'S256')], $commandBus->dispatched());
    }

    public function testTheUserComesFromTheTokenNeverFromTheBody(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus()->willReturn(IssueAuthorizationCodeCommand::class, 'https://alexa.example.test/x');

        // When : un corps qui prétend être un autre utilisateur
        $this->post($this->body(extra: ['userId' => 'someone-else', 'user_id' => 'someone-else']), $user);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame($user->getId(), $commandBus->dispatched()[0]->userId);
    }

    public function testARefusedAuthorizationAnswers422WithOneMessageWhateverTheReason(): void
    {
        // Given
        $user = $this->savedUser();
        $this->mockCommandBus()->willThrow(IssueAuthorizationCodeCommand::class, VoiceException::authorizationRefused());

        // When
        $this->post($this->body(redirectUri: 'https://evil.example.test/callback'), $user);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'VOICE_AUTHORIZATION_REFUSED']], $this->jsonBody());
        $this->assertStringNotContainsString('evil', (string) $this->client->getResponse()->getContent());
    }

    /**
     * @param array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBodies')]
    public function testAnInvalidBodyAnswers422AndDispatchesNothing(array $body): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', self::URL, content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        $valid = ['clientId' => 'repas-alexa', 'redirectUri' => AlexaTestClient::REDIRECT_URI, 'state' => 'xyz', 'codeChallenge' => str_repeat('a', 43), 'codeChallengeMethod' => 'S256'];

        foreach (array_keys($valid) as $field) {
            $without = $valid;
            unset($without[$field]);
            yield "$field missing" => [$without];
            yield "$field empty" => [[$field => ''] + $valid];
            yield "$field not a string" => [[$field => ['x']] + $valid];
        }
        yield 'client id too long' => [['clientId' => str_repeat('a', 101)] + $valid];
        yield 'redirect uri too long' => [['redirectUri' => str_repeat('a', 2049)] + $valid];
        yield 'state too long' => [['state' => str_repeat('a', 1025)] + $valid];
        yield 'challenge too long' => [['codeChallenge' => str_repeat('a', 129)] + $valid];
        yield 'method too long' => [['codeChallengeMethod' => str_repeat('S', 17)] + $valid];
    }

    public function testAnAnonymousCallAnswers401(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', self::URL, content: json_encode($this->body(), JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnAlexaAccessTokenDoesNotOpenTheRoute(): void
    {
        // Given : un jeton opaque comme ceux d'Alexa, présenté comme un jeton porteur
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', self::URL, content: json_encode($this->body(), JSON_THROW_ON_ERROR), server: ['HTTP_AUTHORIZATION' => 'Bearer ' . AlexaTestClient::token('alexa-access'), 'CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnswers404VoiceDisabledWhenTheSwitchIsOff(): void
    {
        // Given
        static::getContainer()->set(VoiceFeature::class, new ControlledVoiceFeature(false));
        $commandBus = $this->mockCommandBus();

        // When : même avec un corps invalide, le 404 passe avant
        $this->client->request('POST', self::URL, content: '{}', server: $this->bearerFor($this->savedUser()) + ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'VOICE_DISABLED']], $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testOnlyPostIsAllowed(): void
    {
        $this->mockCommandBus();

        $this->client->request('GET', self::URL, server: $this->bearerFor($this->savedUser()));

        $this->assertSame(405, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function body(?string $challenge = null, string $redirectUri = AlexaTestClient::REDIRECT_URI, array $extra = []): array
    {
        return $extra + [
            'clientId' => AlexaTestClient::CLIENT_ID,
            'redirectUri' => $redirectUri,
            'state' => 'xyz123',
            'codeChallenge' => $challenge ?? AlexaTestClient::pkce()['challenge'],
            'codeChallengeMethod' => 'S256',
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body, \Repas\User\Domain\Model\User $user): void
    {
        $this->client->request('POST', self::URL, content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
