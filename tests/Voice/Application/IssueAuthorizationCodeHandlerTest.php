<?php

namespace Repas\Tests\Voice\Application;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Alexa\AlexaTestClient;
use Repas\Tests\Helper\AlexaOAuthClientInMemory;
use Repas\Tests\Helper\ControlledOpaqueTokenGenerator;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\AuthorizationCodeInMemoryRepository;
use Repas\Voice\Application\Command\IssueAuthorizationCode\IssueAuthorizationCodeCommand;
use Repas\Voice\Application\Command\IssueAuthorizationCode\IssueAuthorizationCodeHandler;
use Repas\Voice\Domain\Exception\VoiceException;
use Repas\Voice\Domain\Model\AuthorizationCode;
use Repas\Voice\Domain\Model\OpaqueToken;

class IssueAuthorizationCodeHandlerTest extends TestCase
{
    private const string CODE = 'Z0nRzK3q9Yc1uWmP5xVbLfT7sHdJ2eUaGhAoIiNtBvM';

    private DateTimeImmutable $now;
    private AuthorizationCodeInMemoryRepository $codes;
    private IssueAuthorizationCodeHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-25 12:00:00');
        $this->codes = new AuthorizationCodeInMemoryRepository();
        $this->handler = new IssueAuthorizationCodeHandler(
            $this->codes,
            new AlexaOAuthClientInMemory(),
            new ControlledOpaqueTokenGenerator([self::CODE]),
            new FrozenClock($this->now),
        );
    }

    public function testTheCodeIsStoredHashedAndGivenOnlyInTheReturnedAddress(): void
    {
        // Given
        $pkce = AlexaTestClient::pkce();

        // When
        $redirectUrl = ($this->handler)($this->command(challenge: $pkce['challenge']));

        // Then : l'adresse de retour autorisée + state + code en clair
        $this->assertSame(AlexaTestClient::REDIRECT_URI . '?state=xyz123&code=' . self::CODE, $redirectUrl);

        // Then : la base ne reçoit que le haché, lié au client, à l'adresse de retour et au défi, valable 10 minutes, pas encore utilisé
        $stored = $this->codes->all();
        $this->assertCount(1, $stored);
        $this->assertSame(OpaqueToken::hash(self::CODE), $stored[0]->getId());
        $this->assertSame(['user-1', AlexaTestClient::CLIENT_ID, AlexaTestClient::REDIRECT_URI, $pkce['challenge']], [$stored[0]->getUserId(), $stored[0]->getClientId(), $stored[0]->getRedirectUri(), $stored[0]->getCodeChallenge()]);
        $this->assertSame('2026-10-25 12:10:00', $stored[0]->getExpiresAt()->format('Y-m-d H:i:s'));
        $this->assertFalse($stored[0]->isUsed());
        $this->assertStringNotContainsString(self::CODE, json_encode($stored[0]->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testBothAuthorizedRedirectUrisAreAccepted(): void
    {
        $redirectUrl = ($this->handler)($this->command(redirectUri: AlexaTestClient::OTHER_REDIRECT_URI));

        $this->assertStringStartsWith(AlexaTestClient::OTHER_REDIRECT_URI . '?state=', $redirectUrl);
    }

    #[DataProvider('refusedRequests')]
    public function testAnythingThatIsNotExactlyWhatWasConfiguredIsRefusedWithoutSayingWhyAndWritesNothing(array $override): void
    {
        // When
        try {
            ($this->handler)($this->command(...$override));
            $this->fail('La demande aurait dû être refusée.');
        } catch (VoiceException $exception) {
            // Then : un seul message, 422, quelle que soit la raison
            $this->assertSame(VoiceException::AUTHORIZATION_REFUSED, $exception->getMessage());
            $this->assertSame(422, $exception->getCode());
        }
        $this->assertSame([], $this->codes->all());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function refusedRequests(): iterable
    {
        yield 'unknown client' => [['clientId' => 'another-client']];
        yield 'client in another case' => [['clientId' => 'REPAS-ALEXA']];
        yield 'empty client' => [['clientId' => '']];
        yield 'unknown redirect uri' => [['redirectUri' => 'https://evil.example.test/callback']];
        yield 'redirect uri with a path appended' => [['redirectUri' => AlexaTestClient::REDIRECT_URI . '/x']];
        yield 'redirect uri with a query appended' => [['redirectUri' => AlexaTestClient::REDIRECT_URI . '?next=https://evil.example.test']];
        yield 'redirect uri that is only a prefix of an authorized one' => [['redirectUri' => 'https://alexa.example.test/api/skill/link']];
        yield 'authorized redirect uri with another case' => [['redirectUri' => strtoupper(AlexaTestClient::REDIRECT_URI)]];
        yield 'authorized redirect uri with a trailing space' => [['redirectUri' => AlexaTestClient::REDIRECT_URI . ' ']];
        yield 'empty redirect uri' => [['redirectUri' => '']];
        yield 'plain method' => [['challengeMethod' => 'plain']];
        yield 'lower case s256' => [['challengeMethod' => 's256']];
        yield 'empty method' => [['challengeMethod' => '']];
        yield 'challenge too short' => [['challenge' => str_repeat('a', 42)]];
        yield 'challenge too long' => [['challenge' => str_repeat('a', 44)]];
        yield 'challenge with padding' => [['challenge' => str_repeat('a', 42) . '=']];
        yield 'empty challenge' => [['challenge' => '']];
        yield 'empty state' => [['state' => '']];
        yield 'state too long' => [['state' => str_repeat('a', 1025)]];
        yield 'state with a line break' => [['state' => "ab\ncd"]];
        yield 'state with a non ascii character' => [['state' => 'étape']];
    }

    public function testAStateOfTheMaximumLengthPasses(): void
    {
        $redirectUrl = ($this->handler)($this->command(state: str_repeat('a', 1024)));

        $this->assertStringContainsString('state=' . str_repeat('a', 1024) . '&code=', $redirectUrl);
    }

    public function testTheExpiredCodesOfTheUserLeaveAndNobodyElsesStay(): void
    {
        // Given : un code expiré et un code valable du compte, un code expiré d'un autre compte
        $this->codes->add(AuthorizationCode::issue('expired-1', 'user-1', AlexaTestClient::CLIENT_ID, AlexaTestClient::REDIRECT_URI, 'c', $this->now->modify('-1 hour')));
        $this->codes->add(AuthorizationCode::issue('valid-1', 'user-1', AlexaTestClient::CLIENT_ID, AlexaTestClient::REDIRECT_URI, 'c', $this->now->modify('-5 minutes')));
        $this->codes->add(AuthorizationCode::issue('expired-2', 'user-2', AlexaTestClient::CLIENT_ID, AlexaTestClient::REDIRECT_URI, 'c', $this->now->modify('-1 hour')));

        // When
        ($this->handler)($this->command());

        // Then
        $ids = array_map(static fn(AuthorizationCode $code): string => $code->getId(), $this->codes->all());
        $expected = ['expired-2', 'valid-1', OpaqueToken::hash(self::CODE)];
        sort($ids);
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    private function command(string $clientId = AlexaTestClient::CLIENT_ID, string $redirectUri = AlexaTestClient::REDIRECT_URI, string $state = 'xyz123', ?string $challenge = null, string $challengeMethod = 'S256'): IssueAuthorizationCodeCommand
    {
        return new IssueAuthorizationCodeCommand('user-1', $clientId, $redirectUri, $state, $challenge ?? AlexaTestClient::pkce()['challenge'], $challengeMethod);
    }
}
