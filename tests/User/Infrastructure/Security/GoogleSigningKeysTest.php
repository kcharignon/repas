<?php

namespace Repas\Tests\User\Infrastructure\Security;


use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Google\GoogleTestKey;
use Repas\User\Infrastructure\Security\GoogleSigningKeys;
use Repas\User\Infrastructure\Security\GoogleSigningKeysUnavailable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Clés publiques de Google : un faux client HTTP qui compte les appels (aucun accès au réseau, aucune clé de Google : les
 * clés et certificats naissent dans le test), un cache en mémoire et une horloge que le test avance.
 */
class GoogleSigningKeysTest extends TestCase
{
    private static GoogleTestKey $key;
    private static GoogleTestKey $rotatedKey;

    private MockClock $clock;
    private ArrayAdapter $cache;
    private BufferingLogger $logger;
    /** @var list<MockResponse> réponses que « Google » donnera, dans l'ordre */
    private array $google = [];
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    public static function setUpBeforeClass(): void
    {
        self::$key = GoogleTestKey::generate('kid-1');
        self::$rotatedKey = GoogleTestKey::generate('kid-2');
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-19 12:00:00 UTC');
        $this->cache = new ArrayAdapter();
        $this->logger = new BufferingLogger();
        $this->google = [];
        $this->requests = [];
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    public function testTheKeysAreDownloadedFromGoogleWithAThreeSecondTimeout(): void
    {
        // Given
        $this->google[] = $this->certs(self::$key);

        // When
        $pem = $this->keys()->publicKey('kid-1');

        // Then
        $this->assertSame(self::$key->certificatePem, $pem);
        $this->assertCount(1, $this->requests);
        $this->assertSame('GET', $this->requests[0]['method']);
        $this->assertSame('https://www.googleapis.com/oauth2/v1/certs', $this->requests[0]['url']);
        $this->assertEquals(3.0, $this->requests[0]['options']['timeout']);
        $this->assertEquals(3.0, $this->requests[0]['options']['max_duration']);
    }

    public function testFreshKeysAreServedFromTheCacheToEveryWorker(): void
    {
        // Given
        $this->google[] = $this->certs(self::$key, 'public, max-age=3600');
        $this->keys()->publicKey('kid-1');

        // When : plus tard, et depuis un autre worker qui partage le cache
        $this->clock->sleep(3599);
        $pem = $this->keys()->publicKey('kid-1');

        // Then
        $this->assertSame(self::$key->certificatePem, $pem);
        $this->assertCount(1, $this->requests);
    }

    /**
     * @return iterable<string, array{?string, int}>
     */
    public static function cacheControls(): iterable
    {
        yield 'max-age de Google' => ['public, max-age=24560, must-revalidate, no-transform', 24560];
        yield 'max-age trop court : 10 min' => ['public, max-age=60', 600];
        yield 'max-age trop long : 24 h' => ['public, max-age=999999', 86400];
        yield 'max-age nul : 10 min' => ['max-age=0', 600];
        yield 'sans max-age : 10 min' => ['no-cache', 600];
        yield 's-maxage ignoré : 10 min' => ['s-maxage=7200', 600];
        yield 'sans en-tête : 10 min' => [null, 600];
    }

    #[DataProvider('cacheControls')]
    public function testKeysStayFreshForTheirBoundedMaxAge(?string $cacheControl, int $freshness): void
    {
        // Given
        $this->google[] = $this->certs(self::$key, $cacheControl);
        $this->google[] = $this->certs(self::$key, $cacheControl);
        $this->keys()->publicKey('kid-1');

        // When
        $this->clock->sleep($freshness - 1);
        $this->keys()->publicKey('kid-1');
        $stillFresh = count($this->requests);
        $this->clock->sleep(1);
        $this->keys()->publicKey('kid-1');

        // Then
        $this->assertSame(1, $stillFresh, 'fraîches jusqu\'au bout de leur durée');
        $this->assertCount(2, $this->requests, 'téléchargées de nouveau à échéance');
    }

    public function testAnUnknownKidDownloadsTheKeysAtMostOnceEveryFiveMinutes(): void
    {
        // Given
        $this->google[] = $this->certs(self::$key);
        $this->keys()->publicKey('kid-1');

        // When : Google a tourné ses clés, mais on vient de les télécharger
        $this->clock->sleep(60);
        $tooSoon = $this->keys()->publicKey('kid-2');

        // Then
        $this->assertNull($tooSoon);
        $this->assertCount(1, $this->requests);

        // When : cinq minutes après le dernier téléchargement
        $this->clock->sleep(240);
        $this->google[] = $this->certs(self::$key, null, self::$rotatedKey);
        $rotated = $this->keys()->publicKey('kid-2');

        // Then
        $this->assertSame(self::$rotatedKey->certificatePem, $rotated);
        $this->assertCount(2, $this->requests);
    }

    public function testTwoUnknownKidsInTheSameMinuteDownloadTheKeysOnce(): void
    {
        // Given
        $this->google[] = $this->certs(self::$key);

        // When
        $first = $this->keys()->publicKey('kid-inconnu');
        $this->clock->sleep(30);
        $second = $this->keys()->publicKey('kid-inconnu');

        // Then
        $this->assertNull($first);
        $this->assertNull($second);
        $this->assertCount(1, $this->requests);
    }

    public function testKeysOfLessThanADayStillServeWhileGoogleIsUnreachable(): void
    {
        // Given : clés téléchargées à 12:00, fraîches une heure
        $this->google[] = $this->certs(self::$key, 'max-age=3600');
        $this->keys()->publicKey('kid-1');

        // When : périmées, et Google ne répond plus
        $this->clock->sleep(3600);
        $this->google[] = $this->unreachable();
        $pem = $this->keys()->publicKey('kid-1');

        // Then : les clés d'avant servent, et la panne se voit dans le journal
        $this->assertSame(self::$key->certificatePem, $pem);
        $this->assertCount(2, $this->requests);
        $warnings = $this->warnings();
        $this->assertCount(1, $warnings);
        $this->assertSame('Clés publiques de Google injoignables : les clés en cache servent encore.', $warnings[0][1]);

        // When : pendant les cinq minutes qui suivent, on n'insiste pas
        $this->clock->sleep(299);
        $this->keys()->publicKey('kid-1');

        // Then
        $this->assertCount(2, $this->requests);

        // When : juste avant 24 h, toujours injoignable
        $this->clock->modify('2026-10-20 11:59:59 UTC');
        $this->google[] = $this->unreachable();
        $lastSecond = $this->keys()->publicKey('kid-1');

        // Then
        $this->assertSame(self::$key->certificatePem, $lastSecond);
        $this->assertCount(3, $this->requests);

        // When : 24 h après le téléchargement, les clés ne servent plus (échec fermé)
        $this->clock->modify('2026-10-20 12:00:00 UTC');
        $this->expectException(GoogleSigningKeysUnavailable::class);
        $this->keys()->publicKey('kid-1');
    }

    public function testWithoutKeysAndGoogleUnreachableItFailsClosedAndRetriesFiveMinutesLater(): void
    {
        // Given
        $this->google[] = $this->unreachable();
        $this->assertUnavailable();

        // When : une minute plus tard, pas de nouvel essai
        $this->clock->sleep(60);
        $this->assertUnavailable();

        // Then
        $this->assertCount(1, $this->requests);

        // When : cinq minutes après l'essai manqué, Google répond de nouveau
        $this->clock->sleep(240);
        $this->google[] = $this->certs(self::$key);
        $pem = $this->keys()->publicKey('kid-1');

        // Then
        $this->assertSame(self::$key->certificatePem, $pem);
        $this->assertCount(2, $this->requests);
    }

    /**
     * Chaque cas fabrique la réponse au moment du test (les clés de test naissent dans `setUpBeforeClass`).
     *
     * @return iterable<string, array{Closure(): MockResponse}>
     */
    public static function malformedResponses(): iterable
    {
        $json = static fn (mixed $body): MockResponse => new MockResponse(json_encode($body, JSON_THROW_ON_ERROR));

        yield 'HTTP 503' => [static fn (): MockResponse => new MockResponse(GoogleTestKey::certsBody(self::$key), ['http_code' => 503])];
        yield 'HTTP 404' => [static fn (): MockResponse => new MockResponse(GoogleTestKey::certsBody(self::$key), ['http_code' => 404])];
        yield 'HTTP 204' => [static fn (): MockResponse => new MockResponse('', ['http_code' => 204])];
        yield 'HTTP 206 avec des clés' => [static fn (): MockResponse => new MockResponse(GoogleTestKey::certsBody(self::$key), ['http_code' => 206])];
        yield 'corps vide' => [static fn (): MockResponse => new MockResponse('')];
        yield 'pas du JSON' => [static fn (): MockResponse => new MockResponse('<html>Service Unavailable</html>')];
        yield 'objet vide' => [static fn (): MockResponse => new MockResponse('{}')];
        yield 'liste au lieu d\'un objet' => [static fn (): MockResponse => $json([self::$key->certificatePem])];
        yield 'chaîne JSON' => [static fn (): MockResponse => $json('kid-1')];
        yield 'valeur non textuelle' => [static fn (): MockResponse => $json(['kid-1' => 42])];
        yield 'valeur objet' => [static fn (): MockResponse => $json(['kid-1' => ['pem' => self::$key->certificatePem]])];
        yield 'kid vide' => [static fn (): MockResponse => $json(['' => self::$key->certificatePem])];
        yield 'certificat illisible' => [static fn (): MockResponse => $json(['kid-1' => "-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n"])];
        yield 'clé RSA de 1024 bits' => [static fn (): MockResponse => new MockResponse(GoogleTestKey::certsBody(GoogleTestKey::generate('kid-1', 1024)))];
        yield 'clé EC au lieu de RSA' => [static fn (): MockResponse => new MockResponse(GoogleTestKey::certsBody(GoogleTestKey::generate('kid-1', type: OPENSSL_KEYTYPE_EC)))];
        yield 'clé DSA de 2048 bits au lieu de RSA' => [static fn (): MockResponse => new MockResponse(GoogleTestKey::certsBody(GoogleTestKey::generate('kid-1', 2048, OPENSSL_KEYTYPE_DSA)))];
        yield 'chemin de fichier vers une vraie clé' => [static function () use ($json): MockResponse {
            $path = tempnam(sys_get_temp_dir(), 'repas-google-key-');
            file_put_contents($path, self::$key->publicKeyPem);
            register_shutdown_function(static fn (): bool => @unlink($path));

            return $json(['kid-1' => 'file://' . $path]);
        }];
        yield 'une bonne clé et une mauvaise' => [static fn (): MockResponse => $json(['kid-1' => self::$key->certificatePem, 'kid-2' => 'pas une clé'])];
    }

    /**
     * @param Closure(): MockResponse $response
     */
    #[DataProvider('malformedResponses')]
    public function testAMalformedResponseIsNeverRetained(Closure $response): void
    {
        // Given
        $this->google[] = $response();

        // Then : sans clé d'avant, échec fermé
        $this->assertUnavailable();
        $this->assertCount(1, $this->requests);
    }

    /**
     * @param Closure(): MockResponse $response
     */
    #[DataProvider('malformedResponses')]
    public function testAMalformedResponseKeepsThePreviousKeys(Closure $response): void
    {
        // Given
        $this->google[] = $this->certs(self::$key, 'max-age=600');
        $this->keys()->publicKey('kid-1');

        // When
        $this->clock->sleep(600);
        $this->google[] = $response();
        $pem = $this->keys()->publicKey('kid-1');

        // Then
        $this->assertSame(self::$key->certificatePem, $pem);
        $this->assertCount(2, $this->requests);
        $this->assertCount(1, $this->warnings());
    }

    public function testAPublicKeyPemIsAcceptedLikeACertificate(): void
    {
        // Given : la forme d'une clé reconstruite depuis un JWK (`v3/certs`)
        $this->google[] = new MockResponse(json_encode(['kid-1' => self::$key->publicKeyPem], JSON_THROW_ON_ERROR));

        // When
        $pem = $this->keys()->publicKey('kid-1');

        // Then
        $this->assertSame(self::$key->publicKeyPem, $pem);
    }

    public function testANumericKidIsFound(): void
    {
        // Given : PHP range la clé JSON "123" sous l'entier 123
        $this->google[] = new MockResponse(json_encode(['123' => self::$key->certificatePem], JSON_THROW_ON_ERROR));

        // When
        $pem = $this->keys()->publicKey('123');

        // Then
        $this->assertSame(self::$key->certificatePem, $pem);
    }

    public function testACacheEntryOfAnotherShapeIsIgnored(): void
    {
        // Given : entrée laissée par une autre version du code, ou abîmée
        $item = $this->cache->getItem(GoogleSigningKeys::CACHE_KEY);
        $item->set(['keys' => ['kid-1' => 'pas une clé'], 'fetchedAt' => 'hier']);
        $this->cache->save($item);
        $this->google[] = $this->certs(self::$key);

        // When
        $pem = $this->keys()->publicKey('kid-1');

        // Then
        $this->assertSame(self::$key->certificatePem, $pem);
        $this->assertCount(1, $this->requests);
    }

    private function keys(): GoogleSigningKeys
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($this->google) ?? throw new LogicException('Appel inattendu aux clés de Google.');
        });

        return new GoogleSigningKeys($client, $this->cache, $this->clock, $this->logger);
    }

    private function certs(GoogleTestKey $key, ?string $cacheControl = 'public, max-age=3600', GoogleTestKey ...$more): MockResponse
    {
        $headers = ['Content-Type' => 'application/json; charset=UTF-8'];
        if ($cacheControl !== null) {
            $headers['Cache-Control'] = $cacheControl;
        }

        return new MockResponse(GoogleTestKey::certsBody($key, ...$more), ['response_headers' => $headers]);
    }

    private function unreachable(): MockResponse
    {
        return new MockResponse('', ['error' => 'Connexion impossible (test).']);
    }

    private function assertUnavailable(): void
    {
        try {
            $this->keys()->publicKey('kid-1');
            $this->fail('Des clés ont été servies sans téléchargement réussi.');
        } catch (GoogleSigningKeysUnavailable) {
            $this->addToAssertionCount(1);
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
