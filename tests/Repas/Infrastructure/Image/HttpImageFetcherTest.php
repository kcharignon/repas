<?php

namespace Repas\Tests\Repas\Infrastructure\Image;


use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Interface\ImageFetcher;
use Repas\Repas\Domain\Model\ImageUrl;
use Repas\Repas\Domain\Service\ImageNormalizer;
use Repas\Repas\Infrastructure\Image\HttpImageFetcher;
use Repas\Tests\Helper\FixedHostAddressResolver;
use Repas\Tests\Helper\Image\Images;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Le téléchargement d'une image par lien (ADR 0039) avec un faux client HTTP et un faux résolveur de noms : aucun accès au réseau ni au DNS. Ce que les
 * tests prouvent : le nom est résolu et TOUTES ses adresses doivent être publiques avant la moindre connexion, la connexion est imposée à l'adresse
 * vérifiée, chaque redirection est jugée comme le premier lien (trois au plus), la durée, la taille et le type sont bornés, et le journal ne reçoit
 * jamais ni le lien, ni le nom, ni l'adresse, ni le contenu.
 */
class HttpImageFetcherTest extends TestCase
{
    private const string PUBLIC_ADDRESS = '93.184.216.34';
    private const string URL = 'https://exemple.org/images/surgeles.png';

    /** @var list<MockResponse> les réponses que « le site » donnera, dans l'ordre */
    private array $responses = [];
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];
    private FixedHostAddressResolver $resolver;
    private BufferingLogger $logger;

    protected function setUp(): void
    {
        $this->responses = [];
        $this->requests = [];
        $this->resolver = new FixedHostAddressResolver([self::PUBLIC_ADDRESS]);
        $this->logger = new BufferingLogger();
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    private function fetcher(): ImageFetcher
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($this->responses) ?? new MockResponse('aucune réponse programmée', ['http_code' => 599]);
        });

        return new HttpImageFetcher($client, $this->resolver, $this->logger);
    }

    private function fetch(string $url = self::URL): string
    {
        return $this->fetcher()->fetch(ImageUrl::parse($url));
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private function image(string $body, int $status = 200, array $headers = ['content-type' => 'image/png']): MockResponse
    {
        return new MockResponse($body, ['http_code' => $status, 'response_headers' => $headers]);
    }

    private function redirect(string $location, int $status = 302): MockResponse
    {
        return new MockResponse('', ['http_code' => $status, 'response_headers' => ['location' => $location]]);
    }

    private function assertFailure(string $expectedMessage, callable $call): void
    {
        try {
            $call();
            $this->fail('Le téléchargement aurait dû échouer avec ' . $expectedMessage . '.');
        } catch (ImageException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
        }
    }

    // ---------------------------------------------------------------- l'image téléchargée

    public function testAnImageIsDownloadedAsItIs(): void
    {
        // Given
        $png = Images::png();
        $this->responses = [$this->image($png)];

        // When
        $bytes = $this->fetch();

        // Then
        $this->assertSame($png, $bytes);
        $this->assertCount(1, $this->requests);
        $this->assertSame(['GET', self::URL], [$this->requests[0]['method'], $this->requests[0]['url']]);
    }

    public function testTheConnectionIsImposedOnTheVerifiedAddressAndNothingOfTheAdminIsSent(): void
    {
        // Given
        $this->responses = [$this->image(Images::png())];

        // When
        $this->fetch();

        // Then
        $options = $this->requests[0]['options'];
        $this->assertSame(['exemple.org' => self::PUBLIC_ADDRESS], $options['resolve']);
        $this->assertSame(0, $options['max_redirects']);
        $this->assertTrue($options['verify_peer']);
        $this->assertTrue($options['verify_host']);
        $this->assertFalse($options['buffer']);
        $this->assertLessThanOrEqual(10.0, $options['timeout']);
        $this->assertGreaterThan(9.0, $options['timeout']);
        $headers = implode("\n", $options['normalized_headers']['user-agent'] ?? []);
        $this->assertStringContainsString('Repas/1.0 (image demandée par un administrateur)', $headers);
        $this->assertArrayNotHasKey('cookie', $options['normalized_headers']);
        $this->assertArrayNotHasKey('authorization', $options['normalized_headers']);
        $this->assertSame(['exemple.org'], $this->resolver->asked);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function imageContentTypes(): iterable
    {
        yield 'image/png' => ['image/png'];
        yield 'avec un paramètre' => ['image/jpeg; charset=binary'];
        yield 'en majuscules' => ['IMAGE/WebP'];
        yield 'avec des espaces' => ['  image/png  '];
        yield 'un SVG (le contenu décidera)' => ['image/svg+xml'];
        yield 'un type d\'image inconnu (le contenu décidera)' => ['image/x-autre'];
    }

    #[DataProvider('imageContentTypes')]
    public function testAnImageContentTypeIsAccepted(string $contentType): void
    {
        // Given
        $png = Images::png();
        $this->responses = [$this->image($png, 200, ['content-type' => $contentType])];

        // When / Then : le type annoncé ne sert qu'à écarter une page web ; seul le contenu décide ensuite
        $this->assertSame($png, $this->fetch());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function notImageContentTypes(): iterable
    {
        yield 'une page web' => [['content-type' => 'text/html; charset=utf-8']];
        yield 'du JSON' => [['content-type' => 'application/json']];
        yield 'un flux d\'octets' => [['content-type' => 'application/octet-stream']];
        yield 'du texte' => [['content-type' => 'text/plain']];
        yield 'un PDF' => [['content-type' => 'application/pdf']];
        yield 'un type qui commence comme image' => [['content-type' => 'application/image']];
        yield 'aucun type' => [[]];
        yield 'un type vide' => [['content-type' => '']];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('notImageContentTypes')]
    public function testAResponseThatIsNotOfAnImageTypeIsRefusedWithoutReadingIt(array $headers): void
    {
        // Given : un corps qui serait une image parfaite, mais annoncé comme autre chose
        $this->responses = [$this->image(Images::png(), 200, $headers)];

        // When / Then
        $this->assertFailure(ImageException::NOT_AN_IMAGE, fn () => $this->fetch());
    }

    // ---------------------------------------------------------------- les statuts et les pannes

    /**
     * @return iterable<string, array{int}>
     */
    public static function failingStatuses(): iterable
    {
        yield '204' => [204];
        yield '400' => [400];
        yield '401' => [401];
        yield '403' => [403];
        yield '404' => [404];
        yield '410' => [410];
        yield '429' => [429];
        yield '500' => [500];
        yield '503' => [503];
        yield '304 (aucune requête conditionnelle n\'est faite)' => [304];
        yield '300 (ce n\'est pas une redirection qu\'on suit)' => [300];
        yield '301 vers rien (voir plus bas)' => [999];
    }

    #[DataProvider('failingStatuses')]
    public function testAnyStatusButOkIsADownloadFailure(int $status): void
    {
        // Given : même avec un corps qui est une image parfaite, le statut décide
        $this->responses = [$this->image(Images::png(), $status)];

        // When / Then
        $this->assertFailure(ImageException::DOWNLOAD_FAILED, fn () => $this->fetch());
        $this->assertCount(1, $this->requests);
    }

    public function testATimeoutIsADownloadFailure(): void
    {
        // Given
        $this->responses = [new MockResponse('', ['error' => 'Idle timeout reached for "https://exemple.org/images/surgeles.png".'])];

        // When / Then
        $this->assertFailure(ImageException::DOWNLOAD_FAILED, fn () => $this->fetch());
    }

    public function testANetworkOrTlsErrorIsADownloadFailure(): void
    {
        // Given
        $this->responses = [new MockResponse('', ['error' => 'SSL certificate problem: certificate has expired'])];

        // When / Then
        $this->assertFailure(ImageException::DOWNLOAD_FAILED, fn () => $this->fetch());
    }

    public function testAnErrorInTheMiddleOfTheBodyIsADownloadFailure(): void
    {
        // Given : le corps commence puis la connexion tombe
        $body = (static function (): Generator {
            yield "\x89PNG\r\n\x1a\n";
            throw new TransportException('Connection reset by peer');
        })();
        $this->responses = [new MockResponse($body, ['http_code' => 200, 'response_headers' => ['content-type' => 'image/png']])];

        // When / Then
        $this->assertFailure(ImageException::DOWNLOAD_FAILED, fn () => $this->fetch());
    }

    public function testAClientWhichThrowsIsADownloadFailure(): void
    {
        // Given : la fabrique de réponses jette une exception de transport
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Could not resolve host "exemple.org"');
        });

        // When / Then
        $this->assertFailure(ImageException::DOWNLOAD_FAILED, fn () => new HttpImageFetcher($client, $this->resolver, $this->logger)->fetch(ImageUrl::parse(self::URL)));
    }

    // ---------------------------------------------------------------- la taille

    public function testABodyOfExactlyThreeMebibytesIsRead(): void
    {
        // Given
        $body = str_repeat('a', ImageNormalizer::MAX_BYTES);
        $this->responses = [$this->image($body)];

        // When / Then : la taille n'est pas jugée ici de plus près que « au plus 3 Mio » ; le contenu l'est ensuite
        $this->assertSame(ImageNormalizer::MAX_BYTES, strlen($this->fetch()));
    }

    public function testABodyOfOneByteOverThreeMebibytesIsTooLarge(): void
    {
        // Given
        $this->responses = [$this->image(str_repeat('a', ImageNormalizer::MAX_BYTES + 1))];

        // When / Then
        $this->assertFailure(ImageException::TOO_LARGE, fn () => $this->fetch());
    }

    public function testABodyDeclaredOverThreeMebibytesIsRefusedFromItsHeaders(): void
    {
        // Given : la taille annoncée dépasse la borne
        $body = str_repeat('a', ImageNormalizer::MAX_BYTES + 1);
        $this->responses = [$this->image($body, 200, ['content-type' => 'image/png', 'content-length' => (string) strlen($body)])];

        // When / Then
        $this->assertFailure(ImageException::TOO_LARGE, fn () => $this->fetch());
    }

    public function testAnEndlessBodyIsAbandonedAsSoonAsItOverflows(): void
    {
        // Given : un corps infini, par morceaux de 1 Mio
        $received = 0;
        $chunk = str_repeat('a', 1024 * 1024);
        $body = (static function () use ($chunk, &$received): Generator {
            for ($i = 0; $i < 100; ++$i) {
                ++$received;
                yield $chunk;
            }
        })();
        $this->responses = [new MockResponse($body, ['http_code' => 200, 'response_headers' => ['content-type' => 'image/png']])];

        // When / Then : le transfert est abandonné au morceau qui dépasse 3 Mio, le reste n'est jamais reçu
        $this->assertFailure(ImageException::TOO_LARGE, fn () => $this->fetch());
        $this->assertGreaterThan(3, $received);
        $this->assertLessThanOrEqual(5, $received);
    }

    // ---------------------------------------------------------------- les adresses non publiques (SSRF)

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function refusedAddresses(): iterable
    {
        yield 'loopback' => [['127.0.0.1']];
        yield 'tout le 127/8' => [['127.9.9.9']];
        yield 'privée 10/8' => [['10.0.0.5']];
        yield 'privée 172.16/12' => [['172.16.0.1']];
        yield 'privée 172.31' => [['172.31.255.254']];
        yield 'privée 192.168/16' => [['192.168.1.1']];
        yield 'lien local et métadonnées du nuage' => [['169.254.169.254']];
        yield 'adresse partagée 100.64/10' => [['100.64.0.1']];
        yield 'adresse nulle' => [['0.0.0.0']];
        yield '0/8' => [['0.1.2.3']];
        yield 'multicast' => [['224.0.0.1']];
        yield 'diffusion' => [['255.255.255.255']];
        yield 'réservée 240/4' => [['240.0.0.1']];
        yield 'documentation' => [['192.0.2.10']];
        yield 'une publique puis une privée' => [['93.184.216.34', '10.0.0.5']];
        yield 'une privée puis une publique' => [['192.168.0.1', '93.184.216.34']];
        yield 'une publique puis loopback' => [['93.184.216.34', '127.0.0.1']];
        yield 'une publique puis une IPv6 locale (AAAA)' => [['93.184.216.34', '::1']];
        yield 'une publique puis une IPv6 privée' => [['93.184.216.34', 'fd12:3456::1']];
        yield 'une publique puis une IPv6 de lien local' => [['93.184.216.34', 'fe80::1']];
        yield 'une publique puis une IPv4 écrite en IPv6' => [['93.184.216.34', '::ffff:127.0.0.1']];
        yield 'une publique puis du multicast IPv6' => [['93.184.216.34', 'ff02::1']];
        yield 'une publique puis NAT64 vers une adresse locale' => [['93.184.216.34', '64:ff9b::7f00:1']];
        yield 'une publique puis du site-local IPv6' => [['93.184.216.34', 'fec0::1']];
        yield 'ce qui n\'est pas une adresse' => [['exemple.org']];
        yield 'une chaîne vide' => [['']];
        yield 'une adresse mal écrite' => [['93.184.216']];
    }

    /**
     * @param list<string> $addresses
     */
    #[DataProvider('refusedAddresses')]
    public function testANameThatResolvesToANonPublicAddressIsRefusedAndNothingIsSent(array $addresses): void
    {
        // Given
        $this->resolver = new FixedHostAddressResolver($addresses);
        $this->responses = [$this->image(Images::png())];

        // When / Then : aucune connexion n'est ouverte
        $this->assertFailure(ImageException::URL_REFUSED, fn () => $this->fetch());
        $this->assertSame([], $this->requests);
        $this->assertSame(['exemple.org'], $this->resolver->asked);
    }

    public function testANameThatDoesNotResolveIsADownloadFailureAndNothingIsSent(): void
    {
        // Given
        $this->resolver = new FixedHostAddressResolver([]);

        // When / Then
        $this->assertFailure(ImageException::DOWNLOAD_FAILED, fn () => $this->fetch());
        $this->assertSame([], $this->requests);
    }

    public function testANameWithOnlyIpv6AddressesIsADownloadFailureAndNothingIsSent(): void
    {
        // Given : une adresse IPv6 publique, mais personne n'a vérifié ce qu'on trouverait en IPv4
        $this->resolver = new FixedHostAddressResolver(['2606:4700::6810:84e5']);

        // When / Then
        $this->assertFailure(ImageException::DOWNLOAD_FAILED, fn () => $this->fetch());
        $this->assertSame([], $this->requests);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function acceptedAddresses(): iterable
    {
        yield 'une adresse publique' => [['93.184.216.34'], '93.184.216.34'];
        yield 'plusieurs publiques : la première' => [['151.101.1.1', '104.18.3.4'], '151.101.1.1'];
        yield 'au bord de 172.16/12 (172.15)' => [['172.15.0.1'], '172.15.0.1'];
        yield 'au bord de 172.16/12 (172.32)' => [['172.32.0.1'], '172.32.0.1'];
        yield 'une IPv6 publique en plus : l\'IPv4 est imposée' => [['2606:4700::6810:84e5', '104.18.3.4'], '104.18.3.4'];
        yield 'une IPv6 publique après : l\'IPv4 est imposée' => [['104.18.3.4', '2606:4700::6810:84e5'], '104.18.3.4'];
    }

    /**
     * @param list<string> $addresses
     */
    #[DataProvider('acceptedAddresses')]
    public function testOnlyPublicAddressesAreConnectedAndTheFirstIpv4IsImposed(array $addresses, string $expected): void
    {
        // Given
        $this->resolver = new FixedHostAddressResolver($addresses);
        $this->responses = [$this->image(Images::png())];

        // When
        $this->fetch();

        // Then
        $this->assertSame(['exemple.org' => $expected], $this->requests[0]['options']['resolve']);
    }

    // ---------------------------------------------------------------- les redirections

    public function testARedirectionToAnotherPublicHostIsFollowedAndResolvedAgain(): void
    {
        // Given
        $png = Images::png();
        $this->resolver = new FixedHostAddressResolver([self::PUBLIC_ADDRESS]);
        $this->resolver->forHost('cdn.autre.net', ['151.101.1.1']);
        $this->responses = [$this->redirect('https://cdn.autre.net/a/b.png'), $this->image($png)];

        // When
        $bytes = $this->fetch();

        // Then : chaque saut est résolu et connecté à SON adresse vérifiée
        $this->assertSame($png, $bytes);
        $this->assertCount(2, $this->requests);
        $this->assertSame('https://cdn.autre.net/a/b.png', $this->requests[1]['url']);
        $this->assertSame(['cdn.autre.net' => '151.101.1.1'], $this->requests[1]['options']['resolve']);
        $this->assertSame(['exemple.org', 'cdn.autre.net'], $this->resolver->asked);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function redirectStatuses(): iterable
    {
        foreach ([301, 302, 303, 307, 308] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('redirectStatuses')]
    public function testEveryUsualRedirectStatusIsFollowed(int $status): void
    {
        // Given
        $this->responses = [$this->redirect('/images/nouveau.png', $status), $this->image(Images::png())];

        // When
        $this->fetch();

        // Then : un chemin absolu reste sur le même nom
        $this->assertSame('https://exemple.org/images/nouveau.png', $this->requests[1]['url']);
    }

    public function testASchemeLessRedirectionIsFollowedInHttps(): void
    {
        // Given
        $this->resolver->forHost('cdn.autre.net', ['151.101.1.1']);
        $this->responses = [$this->redirect('//cdn.autre.net/b.png'), $this->image(Images::png())];

        // When
        $this->fetch();

        // Then
        $this->assertSame('https://cdn.autre.net/b.png', $this->requests[1]['url']);
    }

    public function testARedirectionToAHostThatResolvesToAPrivateAddressIsRefusedAfterOneRequest(): void
    {
        // Given : le premier nom est public, le second se résout vers le réseau local
        $this->resolver->forHost('piege.autre.net', ['10.0.0.5']);
        $this->responses = [$this->redirect('https://piege.autre.net/b.png'), $this->image(Images::png())];

        // When / Then : une seule requête est partie, la seconde n'est jamais ouverte
        $this->assertFailure(ImageException::URL_REFUSED, fn () => $this->fetch());
        $this->assertCount(1, $this->requests);
        $this->assertSame(['exemple.org', 'piege.autre.net'], $this->resolver->asked);
    }

    public function testARedirectionToTheCloudMetadataAddressIsRefused(): void
    {
        // Given : la redirection vise directement l'adresse des métadonnées, ou un nom qui s'y résout
        $this->resolver->forHost('metadonnees.autre.net', ['169.254.169.254']);

        foreach (['https://169.254.169.254/latest/meta-data/', 'https://metadonnees.autre.net/latest/meta-data/'] as $location) {
            $this->requests = [];
            $this->responses = [$this->redirect($location)];

            // When / Then
            $this->assertFailure(ImageException::URL_REFUSED, fn () => $this->fetch());
            $this->assertCount(1, $this->requests, $location);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedRedirections(): iterable
    {
        yield 'http' => ['http://autre.exemple.net/b.png'];
        yield 'une adresse IP' => ['https://127.0.0.1/b.png'];
        yield 'localhost' => ['https://localhost/b.png'];
        yield 'un autre port' => ['https://autre.exemple.net:8443/b.png'];
        yield 'un chemin relatif' => ['b.png'];
        yield 'un autre schéma' => ['gopher://autre.exemple.net/b.png'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'un nom d\'utilisateur' => ['https://admin@autre.exemple.net/b.png'];
        yield 'sans destination' => [''];
    }

    #[DataProvider('refusedRedirections')]
    public function testARedirectionToARefusedLinkIsRefusedAfterOneRequest(string $location): void
    {
        // Given
        $this->responses = [$this->redirect($location), $this->image(Images::png())];

        // When / Then
        $this->assertFailure(ImageException::URL_REFUSED, fn () => $this->fetch());
        $this->assertCount(1, $this->requests);
    }

    public function testARedirectionWithoutLocationIsRefused(): void
    {
        // Given
        $this->responses = [new MockResponse('', ['http_code' => 302])];

        // When / Then
        $this->assertFailure(ImageException::URL_REFUSED, fn () => $this->fetch());
    }

    public function testThreeRedirectionsAreFollowed(): void
    {
        // Given
        $png = Images::png();
        $this->responses = [$this->redirect('/un.png'), $this->redirect('/deux.png'), $this->redirect('/trois.png'), $this->image($png)];

        // When / Then
        $this->assertSame($png, $this->fetch());
        $this->assertCount(4, $this->requests);
        $this->assertSame('https://exemple.org/trois.png', $this->requests[3]['url']);
    }

    public function testAFourthRedirectionIsRefused(): void
    {
        // Given
        $this->responses = [$this->redirect('/un.png'), $this->redirect('/deux.png'), $this->redirect('/trois.png'), $this->redirect('/quatre.png'), $this->image(Images::png())];

        // When / Then : la quatrième redirection n'est pas suivie
        $this->assertFailure(ImageException::URL_REFUSED, fn () => $this->fetch());
        $this->assertCount(4, $this->requests);
    }

    public function testARedirectionLoopIsRefused(): void
    {
        // Given : le site renvoie toujours vers lui-même
        $this->responses = array_fill(0, 10, $this->redirect(self::URL));

        // When / Then
        $this->assertFailure(ImageException::URL_REFUSED, fn () => $this->fetch());
        $this->assertCount(4, $this->requests);
    }

    public function testTheWholeDownloadIsBoundedByTenSeconds(): void
    {
        // Given : deux sauts
        $this->responses = [$this->redirect('/un.png'), $this->image(Images::png())];

        // When
        $this->fetch();

        // Then : le délai de chaque requête est ce qui reste des 10 secondes, jamais 10 secondes de nouveau
        $first = $this->requests[0]['options'];
        $second = $this->requests[1]['options'];
        $this->assertLessThanOrEqual(10.0, $first['max_duration']);
        $this->assertEqualsWithDelta($first['timeout'], $first['max_duration'], 0.000001);
        $this->assertLessThan($first['max_duration'], $second['max_duration'] + 0.000001);
        $this->assertGreaterThan(0.0, $second['max_duration']);
    }

    // ---------------------------------------------------------------- le journal

    public function testTheLogGetsTheReasonButNeverTheLinkTheHostTheAddressNorTheContent(): void
    {
        // Given : un site qui répond 503 avec un corps secret, un nom, une adresse, un lien qui porte un secret dans sa requête
        $url = 'https://image-secrete.exemple.org/chemin/prive.png?jeton=SECRET-DU-LIEN';
        $this->resolver = new FixedHostAddressResolver(['151.101.77.77', '104.18.3.4']);
        $this->responses = [$this->image('CONTENU-A-NE-JAMAIS-JOURNALISER', 503)];

        // When
        $this->assertFailure(ImageException::DOWNLOAD_FAILED, fn () => $this->fetch($url));

        // Then
        $logs = $this->logger->cleanLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('warning', $logs[0][0]);
        $this->assertSame(['reason' => 'réponse HTTP 503'], $logs[0][2]);
        $this->assertNotLogged(['image-secrete', 'exemple.org', 'SECRET-DU-LIEN', 'prive.png', '151.101.77.77', '104.18.3.4', 'CONTENU-A-NE-JAMAIS-JOURNALISER'], $logs);
    }

    public function testARefusedAddressIsLoggedWithoutTheNameNorTheAddress(): void
    {
        // Given
        $this->resolver = new FixedHostAddressResolver(['169.254.169.254']);

        // When
        $this->assertFailure(ImageException::URL_REFUSED, fn () => $this->fetch('https://piege.exemple.org/a.png'));

        // Then
        $logs = $this->logger->cleanLogs();
        $this->assertCount(1, $logs);
        $this->assertSame(['reason' => 'le nom se résout vers une adresse non publique'], $logs[0][2]);
        $this->assertNotLogged(['piege', 'exemple.org', '169.254.169.254', 'a.png'], $logs);
    }

    public function testTheMessageOfTheClientExceptionIsNeverLogged(): void
    {
        // Given : une exception du client dont le message cite le lien et une adresse
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Could not resolve host "image-secrete.exemple.org" (203.0.113.9) https://image-secrete.exemple.org/a.png?jeton=SECRET');
        });

        // When
        $this->assertFailure(ImageException::DOWNLOAD_FAILED, fn () => new HttpImageFetcher($client, $this->resolver, $this->logger)->fetch(ImageUrl::parse('https://image-secrete.exemple.org/a.png?jeton=SECRET')));

        // Then
        $logs = $this->logger->cleanLogs();
        $this->assertCount(1, $logs);
        $this->assertNotLogged(['image-secrete', 'exemple.org', '203.0.113.9', 'SECRET', 'a.png'], $logs);
    }

    public function testAnErrorMessageSentToTheClientIsOnlyACode(): void
    {
        // Given
        $this->responses = [$this->image('...', 500)];

        // When
        try {
            $this->fetch('https://image-secrete.exemple.org/prive.png');
            $this->fail('Le téléchargement aurait dû échouer.');
        } catch (ImageException $exception) {
            // Then
            $this->assertSame([502, 'IMAGE_DOWNLOAD_FAILED'], [$exception->getCode(), $exception->getMessage()]);
        }
    }

    /**
     * @param list<string>            $secrets
     * @param list<array<int, mixed>> $logs
     */
    private function assertNotLogged(array $secrets, array $logs): void
    {
        $text = json_encode($logs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }
}
