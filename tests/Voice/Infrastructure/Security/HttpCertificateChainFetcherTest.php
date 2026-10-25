<?php

namespace Repas\Tests\Voice\Infrastructure\Security;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Alexa\AlexaTestAuthority;
use Repas\Tests\Helper\Alexa\AlexaTestLeaf;
use Repas\Voice\Domain\Exception\VoiceException;
use Repas\Voice\Domain\Model\CertificateChainUrl;
use Repas\Voice\Infrastructure\Security\HttpCertificateChainFetcher;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Le téléchargement de la chaîne d'Amazon (VOX-203) : un faux client HTTP qui compte les appels (aucun accès à Amazon), un cache en
 * mémoire partagé comme celui des workers, une horloge que le test avance, de vrais certificats nés dans le test.
 */
class HttpCertificateChainFetcherTest extends TestCase
{
    private const string URL = 'https://s3.amazonaws.com/echo.api/echo-api-cert-test.pem';
    private const string OTHER_URL = 'https://s3.amazonaws.com/echo.api/echo-api-cert-test-2.pem';
    private const string SECRET = 'CONTENU-DE-REPONSE-A-NE-JAMAIS-JOURNALISER';

    private static AlexaTestAuthority $intermediate;
    private static AlexaTestLeaf $chain;

    private MockClock $clock;
    private ArrayAdapter $cache;
    private BufferingLogger $logger;
    /** @var list<MockResponse> réponses que « Amazon » donnera, dans l'ordre */
    private array $responses = [];
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    public static function setUpBeforeClass(): void
    {
        self::$intermediate = AlexaTestAuthority::root()->intermediate();
        self::$chain = self::$intermediate->issue();
    }

    protected function setUp(): void
    {
        // 5 s EN AVANCE sur la machine : un certificat émis pendant le test (expiré dès sa seconde d'émission, avec days = 0) l'est
        // toujours pour cette horloge.
        $this->clock = new MockClock(new DateTimeImmutable('@' . (time() + 5)));
        $this->cache = new ArrayAdapter();
        $this->logger = new BufferingLogger();
        $this->responses = [];
        $this->requests = [];
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    private function fetcher(): HttpCertificateChainFetcher
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($this->responses) ?? new MockResponse('aucune réponse programmée', ['http_code' => 500]);
        });

        return new HttpCertificateChainFetcher($client, $this->cache, $this->clock, $this->logger);
    }

    private function url(string $value = self::URL): CertificateChainUrl
    {
        $url = CertificateChainUrl::tryFrom($value);
        $this->assertNotNull($url);

        return $url;
    }

    private function chainResponse(?AlexaTestLeaf $chain = null): MockResponse
    {
        return new MockResponse(($chain ?? self::$chain)->chainPem(), ['http_code' => 200]);
    }

    private function assertUnavailable(string $reason, callable $call): void
    {
        try {
            $call();
        } catch (VoiceException $exception) {
            $this->assertSame(VoiceException::CERTIFICATE_CHAIN_UNAVAILABLE, $exception->getMessage());
            $this->assertSame($reason, $exception->reason);

            return;
        }

        $this->fail('La chaîne aurait dû être indisponible.');
    }

    // —— Le téléchargement ——

    public function testTheChainIsDownloadedStrictlyFromTheNormalizedUrl(): void
    {
        // Given
        $this->responses[] = $this->chainResponse();

        // When
        $chain = $this->fetcher()->fetch($this->url('HTTPS://S3.AMAZONAWS.COM:443//echo.api/echo-api-cert-test.pem#fragment'));

        // Then
        $this->assertSame(self::$chain->chainPem(), $chain);
        $this->assertCount(1, $this->requests);
        $this->assertSame('GET', $this->requests[0]['method']);
        $this->assertSame(self::URL, $this->requests[0]['url']);
        $options = $this->requests[0]['options'];
        $this->assertEquals(2.0, $options['timeout']);
        $this->assertEquals(4.0, $options['max_duration']);
        $this->assertSame(0, $options['max_redirects']);
        $this->assertTrue($options['verify_peer']);
        $this->assertTrue($options['verify_host']);
    }

    public function testOnlyTheCertificatesOfTheResponseAreKept(): void
    {
        // Given : une réponse qui mêle commentaires, clé privée et certificats
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]), $privateKey);
        $this->responses[] = new MockResponse("Bag Attributes\n" . self::SECRET . "\n" . $privateKey . "\n" . self::$chain->chainPem() . "\nfin\n", ['http_code' => 200]);

        // When
        $chain = $this->fetcher()->fetch($this->url());

        // Then
        $this->assertSame(self::$chain->chainPem(), $chain);
        $this->assertStringNotContainsString('PRIVATE KEY', $chain);
        $this->assertStringNotContainsString(self::SECRET, $chain);
    }

    // —— Le cache ——

    public function testTheChainIsDownloadedOnlyOncePerUrlInTheCacheWindow(): void
    {
        // Given
        $this->responses[] = $this->chainResponse();
        $fetcher = $this->fetcher();
        $fetcher->fetch($this->url());

        // When : encore et encore pendant la fenêtre
        $this->clock->sleep(60);
        $second = $fetcher->fetch($this->url());
        $this->clock->sleep(86400 - 61);
        $last = $fetcher->fetch($this->url());

        // Then
        $this->assertSame(self::$chain->chainPem(), $second);
        $this->assertSame(self::$chain->chainPem(), $last);
        $this->assertCount(1, $this->requests);
    }

    public function testTheChainIsDownloadedAgainAfter24Hours(): void
    {
        // Given
        $this->responses[] = $this->chainResponse();
        $this->responses[] = $this->chainResponse();
        $fetcher = $this->fetcher();
        $fetcher->fetch($this->url());

        // When
        $this->clock->sleep(86399);
        $fetcher->fetch($this->url());
        $stillCached = count($this->requests);
        $this->clock->sleep(1);
        $fetcher->fetch($this->url());

        // Then
        $this->assertSame(1, $stillCached);
        $this->assertCount(2, $this->requests);
    }

    public function testEveryWorkerSharesTheSameCache(): void
    {
        // Given : un worker télécharge
        $this->responses[] = $this->chainResponse();
        $this->fetcher()->fetch($this->url());

        // When : un autre, avec le même cache, sert la chaîne
        $chain = $this->fetcher()->fetch($this->url());

        // Then
        $this->assertSame(self::$chain->chainPem(), $chain);
        $this->assertCount(1, $this->requests);
    }

    public function testEachUrlHasItsOwnEntry(): void
    {
        // Given
        $this->responses[] = $this->chainResponse();
        $this->responses[] = $this->chainResponse();
        $fetcher = $this->fetcher();

        // When
        $fetcher->fetch($this->url());
        $fetcher->fetch($this->url(self::OTHER_URL));
        $fetcher->fetch($this->url());
        $fetcher->fetch($this->url(self::OTHER_URL));

        // Then
        $this->assertSame([self::URL, self::OTHER_URL], array_column($this->requests, 'url'));
    }

    public function testTheExpiryOfTheCertificateCutsTheCacheShort(): void
    {
        // Given : un certificat qui expire dans une heure ; la fenêtre de 24 h ne doit jamais le dépasser
        $oneDay = self::$intermediate->issue(days: 1);
        $notAfter = openssl_x509_parse($oneDay->certificatePem)['validTo_time_t'];
        $this->clock = new MockClock(new DateTimeImmutable('@' . ($notAfter - 3600)));
        $this->responses[] = $this->chainResponse($oneDay);
        $this->responses[] = $this->chainResponse($oneDay);
        $fetcher = $this->fetcher();
        $fetcher->fetch($this->url());

        // When : une seconde avant l'expiration, la chaîne sort du cache ; à l'expiration, elle est redemandée
        $this->clock->sleep(3599);
        $fetcher->fetch($this->url());
        $requestsBeforeExpiry = count($this->requests);
        $this->clock->sleep(1);

        // Then : et ce qu'Amazon renvoie alors, expiré, est refusé
        $this->assertUnavailable('chaîne expirée', fn () => $fetcher->fetch($this->url()));
        $this->assertSame(1, $requestsBeforeExpiry);
        $this->assertCount(2, $this->requests);
    }

    public function testAChainThatHasAlreadyExpiredIsRefused(): void
    {
        // Given : un certificat expiré à la seconde de sa création
        $expired = self::$intermediate->issue(days: 0);
        $this->responses[] = $this->chainResponse($expired);

        // When / Then
        $this->assertUnavailable('chaîne expirée', fn () => $this->fetcher()->fetch($this->url()));
    }

    public function testACacheEntryOfAnotherShapeIsIgnored(): void
    {
        // Given : une entrée abîmée ou d'une autre version du code
        $item = $this->cache->getItem(HttpCertificateChainFetcher::CACHE_KEY_PREFIX . hash('sha256', self::URL));
        $item->set(['chain' => 12, 'until' => 'demain']);
        $this->cache->save($item);
        $this->responses[] = $this->chainResponse();

        // When
        $chain = $this->fetcher()->fetch($this->url());

        // Then
        $this->assertSame(self::$chain->chainPem(), $chain);
        $this->assertCount(1, $this->requests);
    }

    // —— Les échecs ——

    /**
     * @return iterable<string, array{callable(): MockResponse, string}> la réponse d'« Amazon » et la raison de l'échec
     */
    public static function failures(): iterable
    {
        yield 'introuvable' => [static fn () => new MockResponse('introuvable', ['http_code' => 404]), 'réponse HTTP 404'];
        yield 'erreur du serveur' => [static fn () => new MockResponse('panne', ['http_code' => 503]), 'réponse HTTP 503'];
        yield 'redirection (jamais suivie)' => [static fn () => new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location' => 'https://example.com/cert.pem']]), 'réponse HTTP 301'];
        yield 'statut 204 sans contenu' => [static fn () => new MockResponse('', ['http_code' => 204]), 'réponse HTTP 204'];
        yield 'réseau coupé' => [static fn () => new MockResponse('', ['error' => 'Connection refused']), 'Connection refused'];
        yield 'trop grosse' => [static fn () => new MockResponse(str_repeat('A', HttpCertificateChainFetcher::MAX_BYTES + 1), ['http_code' => 200]), 'réponse trop grosse'];
        yield 'pas du PEM' => [static fn () => new MockResponse('<html>pas un certificat</html>', ['http_code' => 200]), 'réponse sans chaîne de certificats lisible'];
        yield 'vide' => [static fn () => new MockResponse('', ['http_code' => 200]), 'réponse sans chaîne de certificats lisible'];
        yield 'un certificat tronqué' => [static fn () => new MockResponse("-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n", ['http_code' => 200]), 'réponse sans chaîne de certificats lisible'];
    }

    /**
     * @param callable(): MockResponse $response
     */
    #[DataProvider('failures')]
    public function testAnyFailureMakesTheChainUnavailable(callable $response, string $reason): void
    {
        // Given
        $this->responses[] = $response();

        // When / Then
        $this->assertUnavailable($reason, fn () => $this->fetcher()->fetch($this->url()));
        $this->assertCount(1, $this->requests, 'une seule tentative, aucune redirection suivie');
    }

    public function testMoreThanEightCertificatesAreRefused(): void
    {
        // Given
        $this->responses[] = new MockResponse(str_repeat(self::$chain->certificatePem, 9), ['http_code' => 200]);

        // When / Then
        $this->assertUnavailable('réponse sans chaîne de certificats lisible', fn () => $this->fetcher()->fetch($this->url()));
    }

    public function testAFailureIsRememberedForFifteenSeconds(): void
    {
        // Given : Amazon répond 500, puis une bonne chaîne
        $this->responses[] = new MockResponse('panne', ['http_code' => 500]);
        $this->responses[] = $this->chainResponse();
        $fetcher = $this->fetcher();
        $this->assertUnavailable('réponse HTTP 500', fn () => $fetcher->fetch($this->url()));

        // When : 14 s plus tard, l'adresse n'est pas redemandée
        $this->clock->sleep(14);
        $this->assertUnavailable('réponse HTTP 500', fn () => $fetcher->fetch($this->url()));
        $retriesBefore = count($this->requests);

        // Then : à 15 s, on réessaie, et cette fois la chaîne vient
        $this->clock->sleep(1);
        $chain = $fetcher->fetch($this->url());
        $this->assertSame(1, $retriesBefore);
        $this->assertCount(2, $this->requests);
        $this->assertSame(self::$chain->chainPem(), $chain);
    }

    public function testAnUrlThatFailsDoesNotBlockAnotherOne(): void
    {
        // Given
        $this->responses[] = new MockResponse('panne', ['http_code' => 500]);
        $this->responses[] = $this->chainResponse();
        $fetcher = $this->fetcher();
        $this->assertUnavailable('réponse HTTP 500', fn () => $fetcher->fetch($this->url()));

        // When
        $chain = $fetcher->fetch($this->url(self::OTHER_URL));

        // Then
        $this->assertSame(self::$chain->chainPem(), $chain);
    }

    public function testAFailureIsLoggedWithItsTechnicalReasonAndNeverTheResponse(): void
    {
        // Given
        $this->responses[] = new MockResponse(self::SECRET, ['http_code' => 502]);

        // When
        $this->assertUnavailable('réponse HTTP 502', fn () => $this->fetcher()->fetch($this->url()));

        // Then
        $logs = $this->logger->cleanLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('warning', $logs[0][0]);
        $this->assertSame(['reason' => 'réponse HTTP 502'], $logs[0][2]);
        $this->assertStringNotContainsString(self::SECRET, json_encode($logs, JSON_THROW_ON_ERROR));
    }
}
