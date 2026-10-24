<?php

namespace Repas\Tests\User\Infrastructure\Security;


use DateTimeImmutable;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Google\GoogleTestKey;
use Repas\User\Domain\Exception\IdentityException;
use Repas\User\Domain\Model\VerifiedIdentity;
use Repas\User\Infrastructure\Security\GoogleIdTokenVerifier;
use Repas\User\Infrastructure\Security\GoogleSignInSettings;
use Repas\User\Infrastructure\Security\GoogleSigningKeys;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Vérification des jetons d'identité de Google (AUTH-003) avec de VRAIES signatures RSA : les clés et le certificat naissent
 * dans le test, « Google » est un faux client HTTP qui compte ses appels, l'horloge est figée. Aucun jeton ni aucune clé de
 * Google, aucun accès au réseau.
 */
class GoogleIdTokenVerifierTest extends TestCase
{
    private const string CLIENT_ID = '123456-repastest.apps.googleusercontent.com';
    private const string OTHER_CLIENT_ID = '654321-autre.apps.googleusercontent.com';
    private const string SUBJECT = '100000000000000000001';
    private const string EMAIL = 'a@gmail.com';
    private const string NONCE = 'nonce-de-test-0123456789';
    /** Valeur qui retire la revendication du jeton. */
    private const string ABSENT = "\0absente";

    private static GoogleTestKey $google;
    private static GoogleTestKey $attacker;

    private MockClock $clock;
    private ArrayAdapter $cache;
    private BufferingLogger $logger;
    /** @var list<MockResponse> */
    private array $googleResponses = [];
    /** @var list<string> */
    private array $requestedUrls = [];
    private string $clientId = self::CLIENT_ID;

    public static function setUpBeforeClass(): void
    {
        self::$google = GoogleTestKey::generate('kid-google-1');
        // Même `kid` que Google : seule la clé diffère
        self::$attacker = GoogleTestKey::generate('kid-google-1');
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-19 12:00:00 UTC');
        $this->cache = new ArrayAdapter();
        $this->logger = new BufferingLogger();
        $this->googleResponses = [];
        $this->requestedUrls = [];
        $this->clientId = self::CLIENT_ID;
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    // ——— Acceptés ———

    public function testATokenSignedByGoogleGivesTheVerifiedIdentity(): void
    {
        // Given : un jeton construit par le Builder de lcobucci/jwt, à la forme de ceux de Google
        $now = $this->clock->now();
        $token = new Builder(new JoseEncoder(), ChainedFormatter::default())
            ->issuedBy('https://accounts.google.com')
            ->permittedFor(self::CLIENT_ID)
            ->relatedTo(self::SUBJECT)
            ->issuedAt($now)
            ->expiresAt($now->modify('+1 hour'))
            ->withClaim('azp', self::CLIENT_ID)
            ->withClaim('email', 'A@GMail.com')
            ->withClaim('email_verified', true)
            ->withClaim('nonce', self::NONCE)
            ->withClaim('name', 'Prénom Nom')
            ->withHeader('kid', self::$google->kid)
            ->getToken(new Sha256(), InMemory::plainText(self::$google->privateKeyPem))
            ->toString();

        // When
        $identity = $this->verify($token);

        // Then
        $this->assertSame(self::SUBJECT, $identity->subject);
        $this->assertSame('a@gmail.com', $identity->email);
        $this->assertTrue($identity->emailVerified);
        $this->assertTrue($identity->emailIsAuthoritative());
        $this->assertSame(self::NONCE, $identity->nonce);
        $this->assertNull($identity->hostedDomain);
        $this->assertSame([GoogleSigningKeys::CERTS_URL], $this->requestedUrls);
        $this->assertSame([], $this->warnings());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function googleIssuers(): iterable
    {
        yield 'avec le schéma' => ['https://accounts.google.com'];
        yield 'sans le schéma' => ['accounts.google.com'];
    }

    #[DataProvider('googleIssuers')]
    public function testBothIssuerFormsOfGoogleAreAccepted(string $issuer): void
    {
        $identity = $this->verify($this->token(['iss' => $issuer]));

        $this->assertSame(self::SUBJECT, $identity->subject);
    }

    public function testATokenWithoutNotBeforeIsAccepted(): void
    {
        // Given : piège connu, `StrictValidAt` refuserait un jeton sans `nbf`
        $token = $this->token(['nbf' => self::ABSENT]);

        // When
        $identity = $this->verify($token);

        // Then
        $this->assertSame(self::SUBJECT, $identity->subject);
    }

    public function testThirtySecondsOfClockSkewAreTolerated(): void
    {
        $now = $this->clock->now()->getTimestamp();

        $this->verify($this->token(['exp' => $now - 29]));
        $this->verify($this->token(['iat' => $now + 30]));
        $this->verify($this->token(['nbf' => $now + 30]));

        $this->assertSame([], $this->warnings());
    }

    public function testAnAudienceListHoldingOnlyOurClientIdIsAccepted(): void
    {
        $identity = $this->verify($this->token(['aud' => [self::CLIENT_ID]]));

        $this->assertSame(self::SUBJECT, $identity->subject);
    }

    public function testTheAuthorizedPartyIsNotRead(): void
    {
        // Given : avec Credential Manager, `aud` est l'ID client Web et `azp` peut être l'ID client Android
        $token = $this->token(['azp' => '999999-android.apps.googleusercontent.com']);

        // When
        $identity = $this->verify($token);

        // Then
        $this->assertSame(self::SUBJECT, $identity->subject);
    }

    public function testNonceAndHostedDomainAreOptional(): void
    {
        // When
        $withoutNonce = $this->verify($this->token(['nonce' => self::ABSENT]));
        $workspace = $this->verify($this->token(['email' => 'a@example.com', 'hd' => 'example.com']));

        // Then : le cas d'usage refusera un jeton sans nonce (AUTH-006)
        $this->assertNull($withoutNonce->nonce);
        $this->assertSame('example.com', $workspace->hostedDomain);
        $this->assertFalse($workspace->emailIsAuthoritative());
    }

    public function testClaimNamesAreLoggedAtMostOncePerDayWithoutTheirValues(): void
    {
        // When
        $this->verify($this->token());
        $this->verify($this->token());

        // Then
        $infos = array_values(array_filter($this->logger->cleanLogs(), static fn (array $log): bool => $log[0] === 'info'));
        $this->assertCount(1, $infos);
        $this->assertSame(['claims' => 'aud,azp,email,email_verified,exp,iat,iss,nonce,sub'], $infos[0][2]);
    }

    // ——— Refusés : signature et en-tête ———

    public function testAnHs256TokenForgedWithGooglesPublicKeyAsSecretIsRefused(): void
    {
        $this->assertRefused(self::$google->hmacToken(self::$google->publicKeyPem, $this->claims()), 'signature');
        $this->assertRefused(self::$google->hmacToken(self::$google->certificatePem, $this->claims()), 'signature');
    }

    public function testAnHmacSignatureClaimingRs256IsRefused(): void
    {
        $token = self::$google->hmacToken(self::$google->publicKeyPem, $this->claims(), ['alg' => 'RS256']);

        $this->assertRefused($token, 'signature');
    }

    public function testAnUnsignedTokenIsRefused(): void
    {
        $this->assertRefused(self::$google->unsignedToken($this->claims()), 'signature');
        $this->assertRefused(self::$google->unsignedToken($this->claims(), GoogleTestKey::base64Url('signature')), 'signature');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function otherAlgorithms(): iterable
    {
        yield 'none' => ['none'];
        yield 'None' => ['None'];
        yield 'HS256' => ['HS256'];
        yield 'RS384' => ['RS384'];
        yield 'RS512' => ['RS512'];
        yield 'PS256' => ['PS256'];
        yield 'ES256' => ['ES256'];
        yield 'rs256 en minuscules' => ['rs256'];
        yield 'vide' => [''];
        yield 'absent' => [null];
        yield 'liste' => [['RS256']];
    }

    #[DataProvider('otherAlgorithms')]
    public function testTheAlgorithmIsPinnedToRs256(mixed $algorithm): void
    {
        // Given : signature RSA-SHA256 valide, seul l'en-tête `alg` change
        $token = self::$google->idToken($this->claims(), ['alg' => $algorithm]);

        // Then
        $this->assertRefused($token, 'signature');
        $this->assertSame([], $this->requestedUrls, 'aucune clé téléchargée pour un algorithme refusé');
    }

    public function testATokenSignedByAnotherKeyUnderGooglesKidIsRefused(): void
    {
        $this->assertRefused(self::$attacker->idToken($this->claims()), 'signature');
    }

    public function testAKeyNamedByTheTokenIsNeverTrusted(): void
    {
        // Given : un jeton signé par l'attaquant qui désigne sa propre clé de toutes les façons prévues par JOSE
        $attackerKey = openssl_pkey_get_details(openssl_pkey_get_public(self::$attacker->publicKeyPem));
        $token = self::$attacker->idToken($this->claims(), [
            'jku' => 'https://attaquant.example/keys',
            'x5u' => 'https://attaquant.example/cert.pem',
            'x5c' => [base64_encode(self::$attacker->certificatePem)],
            'jwk' => ['kty' => 'RSA', 'n' => GoogleTestKey::base64Url($attackerKey['rsa']['n']), 'e' => GoogleTestKey::base64Url($attackerKey['rsa']['e'])],
        ]);

        // Then : seule l'adresse de Google est appelée
        $this->assertRefused($token, 'signature');
        $this->assertSame([GoogleSigningKeys::CERTS_URL], $this->requestedUrls);
    }

    public function testATamperedPayloadIsRefused(): void
    {
        // Given : la signature d'un vrai jeton, la charge utile d'un autre (adresse changée)
        [$header, , $signature] = explode('.', self::$google->idToken($this->claims()));
        [, $payload] = explode('.', self::$attacker->idToken($this->claims(['email' => 'victime@gmail.com'])));

        // Then
        $this->assertRefused($header . '.' . $payload . '.' . $signature, 'signature');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidKids(): iterable
    {
        yield 'absent' => [null];
        yield 'vide' => [''];
        yield 'nombre' => [1];
        yield 'liste' => [['kid-google-1']];
    }

    #[DataProvider('invalidKids')]
    public function testATokenWithoutAUsableKidIsRefused(mixed $kid): void
    {
        $this->assertRefused(self::$google->idToken($this->claims(), ['kid' => $kid]), 'signature');
        $this->assertSame([], $this->requestedUrls);
    }

    public function testAnUnknownKidIsRefused(): void
    {
        $this->assertRefused(self::$google->idToken($this->claims(), ['kid' => 'kid-inconnu']), 'signature');
    }

    public function testTwoTokensWithAnUnknownKidInTheSameMinuteDownloadTheKeysOnce(): void
    {
        // When
        $this->assertRefused(self::$google->idToken($this->claims(), ['kid' => 'kid-inconnu']), 'signature');
        $this->clock->sleep(30);
        $this->assertRefused(self::$google->idToken($this->claims(), ['kid' => 'kid-inconnu']), 'signature');

        // Then
        $this->assertCount(1, $this->requestedUrls);
    }

    public function testACriticalHeaderIsRefused(): void
    {
        $this->assertRefused(self::$google->idToken($this->claims(), ['crit' => ['exp'], 'exp' => 1]), 'signature');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokens(): iterable
    {
        $part = static fn (string $json): string => GoogleTestKey::base64Url($json);

        yield 'vide' => [''];
        yield 'un mot' => ['credential'];
        yield 'deux parties' => ['abc.def'];
        yield 'quatre parties' => ['a.b.c.d'];
        yield 'que des points' => ['..'];
        yield 'base64 invalide' => ['@@@.###.$$$'];
        yield 'en-tête qui n\'est pas du JSON' => [$part('pas du json') . '.' . $part('{}') . '.' . $part('sig')];
        yield 'en-tête JSON scalaire' => [$part('"RS256"') . '.' . $part('{}') . '.' . $part('sig')];
        yield 'en-tête vide' => [$part('{}') . '.' . $part('{}') . '.' . $part('sig')];
        yield 'jeton chiffré (JWE)' => [$part('{"alg":"RSA-OAEP","enc":"A256GCM"}') . '.' . $part('{}') . '.' . $part('sig')];
        yield 'plus de 8 Kio' => [str_repeat('a', 8193)];
    }

    #[DataProvider('malformedTokens')]
    public function testAMalformedTokenIsRefused(string $token): void
    {
        $this->assertRefused($token, 'signature');
        $this->assertSame([], $this->requestedUrls);
    }

    public function testUnreadableDatesAreRefused(): void
    {
        $this->assertRefused($this->token(['exp' => 'demain']), 'signature');
        $this->assertRefused($this->token(['iat' => ['2026-10-19']]), 'signature');
    }

    // ——— Refusés : émetteur et audience ———

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function otherIssuers(): iterable
    {
        yield 'barre finale' => ['https://accounts.google.com/'];
        yield 'http' => ['http://accounts.google.com'];
        yield 'majuscules' => ['ACCOUNTS.GOOGLE.COM'];
        yield 'domaine piège' => ['https://accounts.google.com.attaquant.example'];
        yield 'autre fournisseur' => ['https://login.microsoftonline.com'];
        yield 'vide' => [''];
        yield 'absent' => [self::ABSENT];
        yield 'liste' => [['https://accounts.google.com']];
    }

    #[DataProvider('otherIssuers')]
    public function testOnlyGoogleIsAcceptedAsIssuer(mixed $issuer): void
    {
        $this->assertRefused($this->token(['iss' => $issuer]), 'issuer');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function otherAudiences(): iterable
    {
        yield 'autre application' => [self::OTHER_CLIENT_ID];
        yield 'notre ID et un autre' => [[self::CLIENT_ID, self::OTHER_CLIENT_ID]];
        yield 'un autre et notre ID' => [[self::OTHER_CLIENT_ID, self::CLIENT_ID]];
        yield 'notre ID en double' => [[self::CLIENT_ID, self::CLIENT_ID]];
        yield 'objet' => [['client' => self::CLIENT_ID]];
        yield 'casse différente' => [strtoupper(self::CLIENT_ID)];
        yield 'vide' => [''];
        yield 'liste vide' => [[]];
        yield 'nombre' => [123456];
        yield 'absente' => [self::ABSENT];
    }

    #[DataProvider('otherAudiences')]
    public function testOnlyOurClientIdIsAcceptedAsAudience(mixed $audience): void
    {
        $this->assertRefused($this->token(['aud' => $audience]), 'audience');
    }

    public function testWhenTheFeatureIsOffEveryTokenIsRefused(): void
    {
        // Given : `GOOGLE_CLIENT_ID` vide
        $this->clientId = '';

        // Then : même un jeton dont l'audience est vide, et sans appeler Google
        $this->assertRefused($this->token(['aud' => '']), 'audience');
        $this->assertRefused($this->token(), 'audience');
        $this->assertSame([], $this->requestedUrls);
    }

    // ——— Refusés : dates ———

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function outOfTimeClaims(): iterable
    {
        $now = new DateTimeImmutable('2026-10-19 12:00:00 UTC')->getTimestamp();

        yield 'expiré depuis 30 s (marge épuisée)' => [['exp' => $now - 30]];
        yield 'expiré depuis une heure' => [['iat' => $now - 7200, 'exp' => $now - 3600]];
        yield 'émis 31 s dans le futur' => [['iat' => $now + 31]];
        yield 'pas encore valable dans 31 s' => [['nbf' => $now + 31]];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('outOfTimeClaims')]
    public function testATokenOutsideItsValidityWindowIsRefused(array $claims): void
    {
        $this->assertRefused($this->token($claims), 'expired');
    }

    // ——— Refusés : revendications ———

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidClaims(): iterable
    {
        yield 'sans exp' => [['exp' => self::ABSENT]];
        yield 'sans iat' => [['iat' => self::ABSENT]];
        yield 'ni exp ni iat' => [['exp' => self::ABSENT, 'iat' => self::ABSENT]];
        yield 'sans sub' => [['sub' => self::ABSENT]];
        yield 'sub vide' => [['sub' => '']];
        yield 'sub fait d\'espaces' => [['sub' => '   ']];
        yield 'sub nombre' => [['sub' => 100000000000000000]];
        yield 'sub nul' => [['sub' => null]];
        yield 'sub de 256 caractères' => [['sub' => str_repeat('1', 256)]];
        yield 'sans email' => [['email' => self::ABSENT]];
        yield 'email vide' => [['email' => '']];
        yield 'email fait d\'espaces' => [['email' => '  ']];
        yield 'email liste' => [['email' => [self::EMAIL]]];
        yield 'email nul' => [['email' => null]];
        yield 'email de 256 caractères' => [['email' => str_repeat('a', 246) . '@gmail.com']];
        yield 'sans email_verified' => [['email_verified' => self::ABSENT]];
        yield 'email_verified faux' => [['email_verified' => false]];
        yield 'email_verified texte "true"' => [['email_verified' => 'true']];
        yield 'email_verified 1' => [['email_verified' => 1]];
        yield 'email_verified nul' => [['email_verified' => null]];
        yield 'nonce vide' => [['nonce' => '']];
        yield 'nonce nombre' => [['nonce' => 42]];
        yield 'nonce nul' => [['nonce' => null]];
        yield 'hd vide' => [['hd' => '']];
        yield 'hd booléen' => [['hd' => true]];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('invalidClaims')]
    public function testAMissingOrMistypedClaimIsRefused(array $claims): void
    {
        $this->assertRefused($this->token($claims), 'claims');
    }

    // ——— Clés de Google ———

    public function testWithoutKeysAndGoogleUnreachableATokenIsRefused(): void
    {
        // Given
        $this->googleResponses[] = new MockResponse('', ['error' => 'Connexion impossible (test).']);

        // Then : échec fermé
        $this->assertRefused($this->token(), 'keys');
    }

    public function testCachedKeysOfLessThanADayStillServeWhileGoogleIsUnreachable(): void
    {
        // Given : un jeton vérifié à 12:00 (clés téléchargées), puis Google ne répond plus
        $this->verify($this->token());
        $this->googleResponses[] = new MockResponse('', ['error' => 'Connexion impossible (test).']);

        // When : le lendemain à 11:59, clés périmées mais de moins de 24 h
        $this->clock->modify('2026-10-20 11:59:00 UTC');
        $identity = $this->verify($this->token());

        // Then
        $this->assertSame(self::SUBJECT, $identity->subject);
        $this->assertCount(2, $this->requestedUrls);

        // When : à 12:00, les clés ont 24 h
        $this->clock->modify('2026-10-20 12:00:00 UTC');

        // Then
        $this->assertRefused($this->token(), 'keys');
    }

    public function testAnUnreadableCachedKeyNeverVerifies(): void
    {
        // Given : une entrée de cache fraîche mais abîmée (la clé n'en est pas une)
        $now = $this->clock->now()->getTimestamp();
        $item = $this->cache->getItem(GoogleSigningKeys::CACHE_KEY);
        $item->set([
            'keys' => [self::$google->kid => "-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n"],
            'fetchedAt' => $now,
            'freshUntil' => $now + 3600,
            'attemptedAt' => $now,
        ]);
        $this->cache->save($item);

        // Then
        $this->assertRefused($this->token(), 'signature');
        $this->assertSame([], $this->requestedUrls);
    }

    // ——— Outils ———

    private function verifier(): GoogleIdTokenVerifier
    {
        $google = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requestedUrls[] = $url;
            if ($url !== GoogleSigningKeys::CERTS_URL) {
                throw new LogicException(sprintf('Appel réseau interdit : %s %s', $method, $url));
            }

            return array_shift($this->googleResponses) ?? new MockResponse(
                GoogleTestKey::certsBody(self::$google),
                ['response_headers' => ['Cache-Control' => 'public, max-age=3600', 'Content-Type' => 'application/json']],
            );
        });

        return new GoogleIdTokenVerifier(
            new GoogleSignInSettings($this->clientId),
            new GoogleSigningKeys($google, $this->cache, $this->clock, $this->logger),
            $this->clock,
            $this->logger,
            $this->cache,
        );
    }

    private function verify(string $token): VerifiedIdentity
    {
        return $this->verifier()->verify($token);
    }

    /**
     * Les revendications d'un jeton de Google valable maintenant ; `ABSENT` en retire une.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        $now = $this->clock->now()->getTimestamp();
        $claims = array_merge([
            'iss' => 'https://accounts.google.com',
            'azp' => self::CLIENT_ID,
            'aud' => self::CLIENT_ID,
            'sub' => self::SUBJECT,
            'email' => self::EMAIL,
            'email_verified' => true,
            'nonce' => self::NONCE,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $overrides);

        return array_filter($claims, static fn (mixed $value): bool => $value !== self::ABSENT);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function token(array $overrides = []): string
    {
        return self::$google->idToken($this->claims($overrides));
    }

    /**
     * Refus unique et muet : `401` au message identique pour toutes les raisons, UN avertissement qui porte la catégorie,
     * jamais le jeton, l'adresse, le sujet ni le nonce dans le journal.
     */
    private function assertRefused(string $token, string $category): void
    {
        try {
            $this->verify($token);
            $this->fail(sprintf('Jeton accepté, refus « %s » attendu.', $category));
        } catch (IdentityException $exception) {
            $this->assertSame('Invalid Google credential.', $exception->getMessage());
            $this->assertSame(401, $exception->getCode());
        }

        $logs = $this->logger->cleanLogs();
        $warnings = array_values(array_filter($logs, static fn (array $log): bool => $log[0] === 'warning' && $log[1] === 'Jeton d\'identité Google refusé.'));
        $this->assertCount(1, $warnings, 'un seul avertissement par refus');
        $this->assertSame($category, $warnings[0][2]['category']);

        $written = json_encode($logs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach (array_filter([$token, self::EMAIL, 'victime@gmail.com', self::SUBJECT, self::NONCE]) as $secret) {
            $this->assertStringNotContainsString($secret, $written);
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function warnings(): array
    {
        return array_values(array_filter($this->logger->cleanLogs(), static fn (array $log): bool => $log[0] === 'warning'));
    }
}
