<?php

namespace Repas\Tests\Voice\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Alexa\AlexaTestClient;
use Repas\Tests\Helper\ActiveUserCheckerInMemory;
use Repas\Tests\Helper\ContextSpyLogger;
use Repas\Tests\Helper\ControlledOpaqueTokenGenerator;
use Repas\Tests\Helper\ControlledUuidGenerator;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\AccessGrantInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\AuthorizationCodeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\VoiceLinkInMemoryRepository;
use Repas\Voice\Application\Command\ExchangeAuthorizationCode\ExchangeAuthorizationCodeCommand;
use Repas\Voice\Application\Command\ExchangeAuthorizationCode\ExchangeAuthorizationCodeHandler;
use Repas\Voice\Domain\Model\AccessGrant;
use Repas\Voice\Domain\Model\AuthorizationCode;
use Repas\Voice\Domain\Model\OpaqueToken;
use Repas\Voice\Domain\Model\VoiceLink;
use Repas\Voice\Domain\Service\LinkRevoker;

class ExchangeAuthorizationCodeHandlerTest extends TestCase
{
    private const string USER = 'user-1';

    private DateTimeImmutable $now;
    private string $code;
    /** @var array{verifier: string, challenge: string} */
    private array $pkce;
    private AuthorizationCodeInMemoryRepository $codes;
    private VoiceLinkInMemoryRepository $links;
    private AccessGrantInMemoryRepository $grants;
    private ActiveUserCheckerInMemory $activeUsers;
    private ControlledOpaqueTokenGenerator $tokens;
    private ContextSpyLogger $logger;
    private ExchangeAuthorizationCodeHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-25 12:00:00');
        $this->code = AlexaTestClient::token('the-code');
        $this->pkce = AlexaTestClient::pkce();
        $this->codes = new AuthorizationCodeInMemoryRepository();
        $this->links = new VoiceLinkInMemoryRepository();
        $this->grants = new AccessGrantInMemoryRepository();
        $this->activeUsers = new ActiveUserCheckerInMemory([self::USER]);
        $this->tokens = new ControlledOpaqueTokenGenerator([AlexaTestClient::token('access-1'), AlexaTestClient::token('refresh-1'), AlexaTestClient::token('access-2'), AlexaTestClient::token('refresh-2')]);
        $this->logger = new ContextSpyLogger();
        $this->handler = $this->handlerWith($this->codes);
        $this->codes->add($this->issuedCode());
    }

    public function testTheCodeIsExchangedForAPairOfTokensAndANewLink(): void
    {
        // When
        $tokens = ($this->handler)($this->command());

        // Then : les jetons en clair, pour une heure
        $this->assertSame(AlexaTestClient::token('access-1'), $tokens->accessToken);
        $this->assertSame(AlexaTestClient::token('refresh-1'), $tokens->refreshToken);
        $this->assertSame(3600, $tokens->expiresIn);

        // Then : une liaison toute neuve au nom du compte, qu'Alexa n'a pas encore utilisée
        $links = $this->links->all();
        $this->assertCount(1, $links);
        $this->assertSame([self::USER, null, false], [$links[0]->getUserId(), $links[0]->getAlexaUserId(), $links[0]->isRevoked()]);

        // Then : la paire est rangée sous les HACHÉS, rattachée à la liaison et au compte
        $grants = $this->grants->all();
        $this->assertCount(1, $grants);
        $this->assertSame(OpaqueToken::hash(AlexaTestClient::token('access-1')), $grants[0]->getId());
        $this->assertSame(OpaqueToken::hash(AlexaTestClient::token('refresh-1')), $grants[0]->getRefreshHash());
        $this->assertSame([$links[0]->getId(), self::USER], [$grants[0]->getLinkId(), $grants[0]->getUserId()]);
        $this->assertSame('2026-10-25 13:00:00', $grants[0]->getExpiresAt()->format('Y-m-d H:i:s'));

        // Then : le code est consommé et se souvient de la liaison qu'il a créée
        $stored = $this->codes->findOneByHash(OpaqueToken::hash($this->code));
        $this->assertTrue($stored->isUsed());
        $this->assertSame($links[0]->getId(), $stored->getLinkId());

        // Then : aucun jeton ni code en clair dans ce qui est gardé ni dans le journal
        $everything = json_encode([$links[0]->toArray(), $grants[0]->toArray(), $stored->toArray()], JSON_THROW_ON_ERROR);
        foreach ([$this->code, AlexaTestClient::token('access-1'), AlexaTestClient::token('refresh-1'), $this->pkce['verifier']] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
            $this->assertStringNotContainsString($secret, $this->logger->everything());
        }
    }

    public function testLinkingAgainReusesTheLinkAlexaHasNotUsedYetAndKillsItsOldTokens(): void
    {
        // Given : le compte a une liaison qu'Alexa n'a jamais utilisée, avec une paire de jetons
        $this->links->save(VoiceLink::create('link-awaiting', self::USER, $this->now->modify('-1 day')));
        $this->grants->add(AccessGrant::issue('old-access', 'old-refresh', 'link-awaiting', self::USER, $this->now->modify('-1 day')));

        // When
        $tokens = ($this->handler)($this->command());

        // Then : la même liaison, une seule paire (l'ancienne est morte)
        $this->assertNotNull($tokens);
        $this->assertCount(1, $this->links->all());
        $grants = $this->grants->all();
        $this->assertCount(1, $grants);
        $this->assertSame(OpaqueToken::hash(AlexaTestClient::token('access-1')), $grants[0]->getId());
        $this->assertSame('link-awaiting', $grants[0]->getLinkId());
        $this->assertSame('link-awaiting', $this->codes->findOneByHash(OpaqueToken::hash($this->code))->getLinkId());
    }

    public function testALinkAlexaAlreadyUsedOrARevokedOneOrAnotherUsersIsNotReused(): void
    {
        // Given : une liaison déjà utilisée par Alexa, une retirée, et une qu'Alexa n'a pas utilisée mais d'un autre compte
        $this->links->save(VoiceLink::load(['id' => 'link-used', 'user_id' => self::USER, 'alexa_user_id' => 'amzn1.ask.account.TEST', 'created_at' => $this->now->modify('-3 days'), 'last_used_at' => $this->now->modify('-1 day'), 'revoked_at' => null]));
        $this->links->save(VoiceLink::create('link-revoked', self::USER, $this->now->modify('-2 days'))->revoke($this->now->modify('-1 day')));
        $this->links->save(VoiceLink::create('link-other', 'user-2', $this->now->modify('-1 day')));
        $this->grants->add(AccessGrant::issue('used-access', 'used-refresh', 'link-used', self::USER, $this->now->modify('-1 day')));

        // When
        $tokens = ($this->handler)($this->command());

        // Then : une quatrième liaison est créée, les autres et leurs jetons sont intacts
        $this->assertNotNull($tokens);
        $this->assertCount(4, $this->links->all());
        $newLinkId = $this->codes->findOneByHash(OpaqueToken::hash($this->code))->getLinkId();
        $this->assertNotContains($newLinkId, ['link-used', 'link-revoked', 'link-other']);
        $this->assertContains('used-access', array_map(static fn(AccessGrant $grant): string => $grant->getId(), $this->grants->all()));
    }

    public function testACodeThatIsNotOneOfOursOrUnknownIsRefusedAndNothingChanges(): void
    {
        foreach (['', 'short', str_repeat('a', 43) /* well formed, unknown */, 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ1c2VyIn0.c2lnbmF0dXJl'] as $code) {
            // When
            $tokens = ($this->handler)($this->command(code: $code));

            // Then
            $this->assertNull($tokens, $code);
        }
        $this->assertNothingWasIssued();
        $this->assertFalse($this->codes->findOneByHash(OpaqueToken::hash($this->code))->isUsed());
    }

    public function testACodeIssuedToAnotherClientIsRefusedAndNotBurned(): void
    {
        // When
        $tokens = ($this->handler)($this->command(clientId: 'another-client'));

        // Then : pas pour lui, et le code reste intact pour son vrai client
        $this->assertNull($tokens);
        $this->assertNothingWasIssued();
        $this->assertFalse($this->codes->findOneByHash(OpaqueToken::hash($this->code))->isUsed());
        $this->assertNotNull(($this->handler)($this->command()));
    }

    public function testAnExpiredCodeIsRefused(): void
    {
        // Given : le code a été émis une minute avant `now`, il expire donc neuf minutes après ; dix minutes exactement après l'émission
        $handler = $this->handlerAt($this->now->modify('+9 minutes'));

        // When / Then
        $this->assertNull($handler($this->command()));
        $this->assertNothingWasIssued();
        $this->assertFalse($this->codes->findOneByHash(OpaqueToken::hash($this->code))->isUsed(), 'un code expiré n\'est pas brûlé : il ne sert plus de toute façon');

        // Given : une seconde avant
        $this->assertNotNull($this->handlerAt($this->now->modify('+539 seconds'))($this->command()));
    }

    public function testAnotherRedirectUriRefusesAndBurnsTheCode(): void
    {
        // When : l'autre adresse autorisée, pas celle de l'autorisation
        $tokens = ($this->handler)($this->command(redirectUri: AlexaTestClient::OTHER_REDIRECT_URI));

        // Then : refus, et le code est brûlé même pour le bon appel qui suit
        $this->assertNull($tokens);
        $this->assertNothingWasIssued();
        $this->assertTrue($this->codes->findOneByHash(OpaqueToken::hash($this->code))->isUsed());
        $this->assertNull(($this->handler)($this->command()));
        $this->assertNothingWasIssued();
    }

    public function testAWrongOrMalformedVerifierRefusesAndBurnsTheCode(): void
    {
        foreach ([AlexaTestClient::pkce('b')['verifier'], 'short', $this->pkce['challenge']] as $verifier) {
            // Given
            $this->setUp();

            // When
            $tokens = ($this->handler)($this->command(codeVerifier: $verifier));

            // Then
            $this->assertNull($tokens, $verifier);
            $this->assertNothingWasIssued();
            $this->assertTrue($this->codes->findOneByHash(OpaqueToken::hash($this->code))->isUsed());
            $this->assertNull(($this->handler)($this->command()), 'le bon vérificateur ne sert plus : le code est brûlé');
        }
    }

    public function testAnInactiveAccountGetsNothingAndTheCodeIsBurned(): void
    {
        // Given : le compte a été désactivé (ou supprimé) entre l'autorisation et l'échange
        $this->activeUsers->deactivate(self::USER);

        // When
        $tokens = ($this->handler)($this->command());

        // Then
        $this->assertNull($tokens);
        $this->assertNothingWasIssued();
        $this->assertTrue($this->codes->findOneByHash(OpaqueToken::hash($this->code))->isUsed());
    }

    public function testReplayingACodeAlreadyExchangedRevokesTheLinkItCreatedAndItsTokens(): void
    {
        // Given : un premier échange réussi
        $first = ($this->handler)($this->command());
        $linkId = $this->links->all()[0]->getId();
        $this->assertNotNull($first);

        // When : le même code revient (même avec les bonnes valeurs)
        $replay = ($this->handler)($this->command());

        // Then : refus ; la liaison est révoquée et ses jetons effacés ; l'événement est journalisé sans secret
        $this->assertNull($replay);
        $this->assertTrue($this->links->findOneById($linkId)->isRevoked());
        $this->assertSame([], $this->grants->all());
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('warning', $this->logger->records[0]['level']);
        $this->assertStringNotContainsString($this->code, $this->logger->everything());
        $this->assertStringNotContainsString($first->accessToken, $this->logger->everything());
    }

    public function testReplayingACodeBurnedByARefusedExchangeRevokesNothing(): void
    {
        // Given : une liaison existante, un échange refusé qui brûle le code
        $this->links->save(VoiceLink::create('link-awaiting', self::USER, $this->now->modify('-1 day')));
        $this->grants->add(AccessGrant::issue('access', 'refresh', 'link-awaiting', self::USER, $this->now->modify('-1 day')));
        ($this->handler)($this->command(redirectUri: AlexaTestClient::OTHER_REDIRECT_URI));

        // When
        $replay = ($this->handler)($this->command());

        // Then : le code brûlé n'a créé aucune liaison, rien n'est révoqué
        $this->assertNull($replay);
        $this->assertFalse($this->links->findOneById('link-awaiting')->isRevoked());
        $this->assertCount(1, $this->grants->all());
    }

    public function testOfTwoSimultaneousExchangesOnlyOneWins(): void
    {
        // Given : un autre échange du même code gagne la course entre la lecture du code et son écriture
        $racing = new class extends AuthorizationCodeInMemoryRepository {
            public function consume(string $codeHash, DateTimeImmutable $now, ?string $linkId): bool
            {
                parent::consume($codeHash, $now, null);

                return false;
            }
        };
        $racing->add($this->issuedCode());
        $handler = $this->handlerWith($racing);

        // When
        $tokens = $handler($this->command());

        // Then : aucun jeton, aucune liaison, aucune paire
        $this->assertNull($tokens);
        $this->assertNothingWasIssued();
    }

    private function assertNothingWasIssued(): void
    {
        $this->assertSame([], $this->grants->all());
        $this->assertSame([], array_filter($this->links->all(), static fn(VoiceLink $link): bool => $link->getId() !== 'link-awaiting' && $link->getUserId() === self::USER && $link->getCreatedAt() >= new DateTimeImmutable('2026-10-25 12:00:00')));
    }

    private function issuedCode(): AuthorizationCode
    {
        return AuthorizationCode::issue(OpaqueToken::hash($this->code), self::USER, AlexaTestClient::CLIENT_ID, AlexaTestClient::REDIRECT_URI, $this->pkce['challenge'], $this->now->modify('-1 minute'));
    }

    private function command(string $clientId = AlexaTestClient::CLIENT_ID, ?string $code = null, string $redirectUri = AlexaTestClient::REDIRECT_URI, ?string $codeVerifier = null): ExchangeAuthorizationCodeCommand
    {
        return new ExchangeAuthorizationCodeCommand($clientId, $code ?? $this->code, $redirectUri, $codeVerifier ?? $this->pkce['verifier']);
    }

    private function handlerAt(DateTimeImmutable $now): ExchangeAuthorizationCodeHandler
    {
        return $this->handlerWith($this->codes, $now);
    }

    private function handlerWith(AuthorizationCodeInMemoryRepository $codes, ?DateTimeImmutable $now = null): ExchangeAuthorizationCodeHandler
    {
        return new ExchangeAuthorizationCodeHandler(
            $codes,
            $this->links,
            $this->grants,
            new LinkRevoker($this->links, $this->grants),
            $this->activeUsers,
            $this->tokens,
            new ControlledUuidGenerator(['new-link-id']),
            new FrozenClock($now ?? $this->now),
            $this->logger,
        );
    }
}
