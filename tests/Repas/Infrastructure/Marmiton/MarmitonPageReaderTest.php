<?php

namespace Repas\Tests\Repas\Infrastructure\Marmiton;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Repas\Repas\Domain\Exception\MarmitonImportException;
use Repas\Repas\Domain\Interface\MarmitonPageReader;
use Repas\Repas\Domain\Model\MarmitonRecipePage;
use Repas\Repas\Domain\Model\MarmitonUrl;
use Repas\Repas\Infrastructure\Marmiton\MarmitonHttpPageReader;
use Repas\Repas\Infrastructure\Marmiton\MarmitonRecipeExtractor;
use Repas\Tests\Helper\FixedHostAddressResolver;
use Repas\Tests\Helper\MarmitonPages;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Le lecteur de page Marmiton (ADR 0037 décisions 12 et 13, MAR-001) avec un faux client HTTP et un faux résolveur de noms : aucun accès à
 * Marmiton ni au DNS. Ce que les tests prouvent : l'adresse appelée est reconstruite, la connexion est imposée à une adresse publique vérifiée,
 * les redirections, les délais, la taille et le type sont bornés, et les échecs ont le bon nom.
 */
class MarmitonPageReaderTest extends TestCase
{
    private const string PUBLIC_ADDRESS = '93.184.216.34';
    private const string EXPECTED_URL = 'https://www.marmiton.org/recettes/recette_the-tarte-au-citron-meringuee_22082.aspx';

    /** @var list<MockResponse> les réponses que « Marmiton » donnera, dans l'ordre */
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

    private function reader(): MarmitonPageReader
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($this->responses) ?? new MockResponse('aucune réponse programmée', ['http_code' => 599]);
        });

        return new MarmitonHttpPageReader($client, $this->resolver, new MarmitonRecipeExtractor(), $this->logger);
    }

    private function url(string $raw = self::EXPECTED_URL): MarmitonUrl
    {
        return MarmitonUrl::parse($raw);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private function html(string $body, int $status = 200, array $headers = ['content-type' => 'text/html; charset=utf-8']): MockResponse
    {
        return new MockResponse($body, ['http_code' => $status, 'response_headers' => $headers]);
    }

    private function redirect(string $location, int $status = 301): MockResponse
    {
        return new MockResponse('', ['http_code' => $status, 'response_headers' => ['location' => $location]]);
    }

    private function read(?MarmitonUrl $url = null): MarmitonRecipePage
    {
        return $this->reader()->read($url ?? $this->url());
    }

    private function assertFailure(string $expectedMessage, callable $call): void
    {
        try {
            $call();
            $this->fail('La lecture aurait dû échouer avec ' . $expectedMessage . '.');
        } catch (MarmitonImportException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
        }
    }

    // ---------------------------------------------------------------- la page lue

    public function testAValidPageGivesItsFacts(): void
    {
        // Given
        $this->responses = [$this->html(MarmitonPages::example())];

        // When
        $page = $this->read();

        // Then
        $this->assertSame(self::EXPECTED_URL, $page->canonicalUrl);
        $this->assertSame(MarmitonPages::EXAMPLE_TITLE, $page->title);
        $this->assertSame('6 personnes', $page->servingsText);
        $this->assertSame('Dessert', $page->category);
        $this->assertSame(MarmitonPages::EXAMPLE_LINES, $page->ingredientLines);
        $this->assertCount(1, $this->requests);
    }

    public function testAGraphPageIsRead(): void
    {
        // Given
        $this->responses = [$this->html(MarmitonPages::page([['@context' => 'https://schema.org', '@graph' => [['@type' => 'WebPage'], MarmitonPages::recipe()]]]))];

        // When / Then
        $this->assertSame(MarmitonPages::EXAMPLE_TITLE, $this->read()->title);
    }

    public function testAListOfTypesIsRead(): void
    {
        // Given
        $this->responses = [$this->html(MarmitonPages::page([MarmitonPages::recipe(['@type' => ['Recipe', 'Thing']])]))];

        // When / Then
        $this->assertSame(MarmitonPages::EXAMPLE_TITLE, $this->read()->title);
    }

    public function testAnotherContentTypeCaseIsAccepted(): void
    {
        // Given
        $this->responses = [$this->html(MarmitonPages::example(), 200, ['Content-Type' => 'Text/HTML'])];

        // When / Then
        $this->assertSame(MarmitonPages::EXAMPLE_TITLE, $this->read()->title);
    }

    // ---------------------------------------------------------------- l'appel

    public function testTheCallIsOneGetToTheRebuiltAddressWithTheHonestAgentAndNothingMore(): void
    {
        // Given : l'adresse collée porte un autre schéma, une requête, un fragment et un autre hôte écrit
        $this->responses = [$this->html(MarmitonPages::example())];
        $pasted = MarmitonUrl::parse('http://MARMITON.org/recettes/recette_the-tarte-au-citron-meringuee_22082.aspx?utm_source=x&next=https://evil.example/#frag');

        // When
        $this->read($pasted);

        // Then
        $this->assertCount(1, $this->requests);
        $request = $this->requests[0];
        $this->assertSame('GET', $request['method']);
        $this->assertSame(self::EXPECTED_URL, $request['url']);

        // les en-têtes : l'agent et la langue, et seulement l'`Accept` que le client ajoute de lui-même ; ni cookie, ni autorisation
        $headers = $request['options']['headers'];
        $this->assertSame(
            ['User-Agent: Repas/1.0 (copie de recette demandée par un utilisateur)', 'Accept-Language: fr', 'Accept: */*'],
            $headers,
        );
        $this->assertArrayNotHasKey('auth_basic', array_filter($request['options']));
        $this->assertArrayNotHasKey('auth_bearer', array_filter($request['options']));
        $this->assertArrayNotHasKey('body', array_filter($request['options']));
        $this->assertArrayNotHasKey('json', array_filter($request['options']));
        $this->assertArrayNotHasKey('proxy', array_filter($request['options']));
    }

    public function testTheCallIsBoundedAndPinnedToTheCheckedAddress(): void
    {
        // Given
        $this->responses = [$this->html(MarmitonPages::example())];

        // When
        $this->read();

        // Then
        $options = $this->requests[0]['options'];
        $this->assertSame(0, $options['max_redirects']);
        $this->assertTrue($options['verify_peer']);
        $this->assertTrue($options['verify_host']);
        $this->assertFalse($options['buffer']);
        $this->assertLessThanOrEqual(8.0, $options['timeout']);
        $this->assertGreaterThan(7.0, $options['timeout']);
        $this->assertLessThanOrEqual(8.0, $options['max_duration']);
        $this->assertSame(['www.marmiton.org' => self::PUBLIC_ADDRESS], $options['resolve']);
        $this->assertSame(['www.marmiton.org'], $this->resolver->asked);
    }

    // ---------------------------------------------------------------- les statuts

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function failingStatuses(): iterable
    {
        yield '404' => [404, MarmitonImportException::RECIPE_NOT_FOUND];
        yield '410' => [410, MarmitonImportException::RECIPE_NOT_FOUND];
        yield '400' => [400, MarmitonImportException::UNAVAILABLE];
        yield '401' => [401, MarmitonImportException::UNAVAILABLE];
        yield '403 (un refus du serveur)' => [403, MarmitonImportException::UNAVAILABLE];
        yield '429' => [429, MarmitonImportException::UNAVAILABLE];
        yield '500' => [500, MarmitonImportException::UNAVAILABLE];
        yield '502' => [502, MarmitonImportException::UNAVAILABLE];
        yield '503' => [503, MarmitonImportException::UNAVAILABLE];
        yield '504' => [504, MarmitonImportException::UNAVAILABLE];
        yield '204' => [204, MarmitonImportException::UNAVAILABLE];
        yield '206' => [206, MarmitonImportException::UNAVAILABLE];
        yield '304' => [304, MarmitonImportException::UNAVAILABLE];
        yield '300' => [300, MarmitonImportException::UNAVAILABLE];
    }

    #[DataProvider('failingStatuses')]
    public function testAFailingStatusHasItsNamedError(int $status, string $expected): void
    {
        // Given : même avec un corps qui porte une recette parfaite, le statut décide
        $this->responses = [$this->html(MarmitonPages::example(), $status)];

        // When / Then
        $this->assertFailure($expected, fn () => $this->read());
        $this->assertCount(1, $this->requests);
    }

    public function testATimeoutIsUnavailable(): void
    {
        // Given
        $this->responses = [new MockResponse('', ['error' => 'Idle timeout reached for "https://www.marmiton.org/".'])];

        // When / Then
        $this->assertFailure(MarmitonImportException::UNAVAILABLE, fn () => $this->read());
    }

    public function testANetworkOrTlsErrorIsUnavailable(): void
    {
        // Given
        $this->responses = [new MockResponse('', ['error' => 'SSL certificate problem: certificate has expired'])];

        // When / Then
        $this->assertFailure(MarmitonImportException::UNAVAILABLE, fn () => $this->read());
    }

    public function testAnErrorInTheMiddleOfTheBodyIsUnavailable(): void
    {
        // Given : le corps commence puis la connexion tombe
        $body = (static function (): \Generator {
            yield '<html><head><script type="application/ld+json">{"@type":';
            throw new \Symfony\Component\HttpClient\Exception\TransportException('Connection reset by peer');
        })();
        $this->responses = [new MockResponse($body, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']])];

        // When / Then
        $this->assertFailure(MarmitonImportException::UNAVAILABLE, fn () => $this->read());
    }

    public function testAClientWhichThrowsIsUnavailable(): void
    {
        // Given : la fabrique de réponses jette une exception de transport
        $client = new MockHttpClient(static function (): MockResponse {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('Could not resolve host');
        });
        $reader = new MarmitonHttpPageReader($client, $this->resolver, new MarmitonRecipeExtractor(), $this->logger);

        // When / Then
        $this->assertFailure(MarmitonImportException::UNAVAILABLE, fn () => $reader->read($this->url()));
    }

    // ---------------------------------------------------------------- les redirections

    public function testARedirectionToTheRightFormIsFollowedOnce(): void
    {
        // Given : un faux nom de recette est redirigé vers l'adresse exacte
        $this->responses = [
            $this->redirect(self::EXPECTED_URL),
            $this->html(MarmitonPages::example()),
        ];

        // When
        $page = $this->read($this->url('https://www.marmiton.org/recettes/recette_x_22082.aspx'));

        // Then : la page est lue à l'adresse exacte, qui devient l'adresse canonique
        $this->assertCount(2, $this->requests);
        $this->assertSame('https://www.marmiton.org/recettes/recette_x_22082.aspx', $this->requests[0]['url']);
        $this->assertSame(self::EXPECTED_URL, $this->requests[1]['url']);
        $this->assertSame(self::EXPECTED_URL, $page->canonicalUrl);
        $this->assertSame(0, $this->requests[1]['options']['max_redirects']);
        $this->assertSame(['www.marmiton.org' => self::PUBLIC_ADDRESS], $this->requests[1]['options']['resolve']);
        $this->assertSame($this->requests[0]['options']['headers'], $this->requests[1]['options']['headers']);
        $this->assertLessThanOrEqual(8.0, $this->requests[1]['options']['max_duration']);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function redirectionStatuses(): iterable
    {
        yield '301' => [301];
        yield '302' => [302];
        yield '303' => [303];
        yield '307' => [307];
        yield '308' => [308];
    }

    #[DataProvider('redirectionStatuses')]
    public function testEveryRedirectionStatusIsFollowedOnce(int $status): void
    {
        // Given
        $this->responses = [$this->redirect(self::EXPECTED_URL, $status), $this->html(MarmitonPages::example())];

        // When / Then
        $this->assertSame(MarmitonPages::EXAMPLE_TITLE, $this->read($this->url('https://www.marmiton.org/recettes/recette_x_22082.aspx'))->title);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedLocations(): iterable
    {
        yield 'une adresse absolue' => [self::EXPECTED_URL, self::EXPECTED_URL];
        yield 'en http' => ['http://www.marmiton.org/recettes/recette_the-tarte-au-citron-meringuee_22082.aspx', self::EXPECTED_URL];
        yield 'sans www' => ['https://marmiton.org/recettes/recette_the-tarte-au-citron-meringuee_22082.aspx', self::EXPECTED_URL];
        yield 'avec une requête' => [self::EXPECTED_URL . '?xtor=1', self::EXPECTED_URL];
        yield 'un chemin relatif' => ['/recettes/recette_the-tarte-au-citron-meringuee_22082.aspx', self::EXPECTED_URL];
    }

    #[DataProvider('acceptedLocations')]
    public function testTheFollowedAddressIsRebuiltNotCopied(string $location, string $expectedCall): void
    {
        // Given
        $this->responses = [$this->redirect($location), $this->html(MarmitonPages::example())];

        // When
        $page = $this->read($this->url('https://www.marmiton.org/recettes/recette_x_22082.aspx'));

        // Then
        $this->assertSame($expectedCall, $this->requests[1]['url']);
        $this->assertSame($expectedCall, $page->canonicalUrl);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedLocations(): iterable
    {
        yield 'un autre hôte' => ['https://evil.example/recettes/recette_x_22082.aspx'];
        yield 'un hôte qui commence par marmiton.org' => ['https://marmiton.org.evil.example/recettes/recette_x_22082.aspx'];
        yield 'un sous-domaine' => ['https://m.marmiton.org/recettes/recette_x_22082.aspx'];
        yield 'une adresse interne' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'localhost' => ['http://localhost/recettes/recette_x_22082.aspx'];
        yield 'un autre port' => ['https://www.marmiton.org:8443/recettes/recette_x_22082.aspx'];
        yield 'un identifiant dans l\'adresse' => ['https://user@www.marmiton.org/recettes/recette_x_22082.aspx'];
        yield 'un autre chemin du même hôte' => ['https://www.marmiton.org/recettes/recherche.aspx?aqt=tarte'];
        yield 'la page d\'accueil' => ['https://www.marmiton.org/'];
        yield 'une autre recette' => ['https://www.marmiton.org/recettes/recette_autre-tarte_99999.aspx'];
        yield 'une adresse sans schéma' => ['//evil.example/recettes/recette_x_22082.aspx'];
        yield 'un autre schéma' => ['ftp://www.marmiton.org/recettes/recette_x_22082.aspx'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'un chemin relatif sans barre' => ['recettes/recette_x_22082.aspx'];
        yield 'un chemin relatif hors du dossier des recettes' => ['/membre/connexion.aspx'];
        yield 'une adresse vide' => [''];
        yield 'des espaces' => ['   '];
    }

    #[DataProvider('refusedLocations')]
    public function testARedirectionOutOfTheRecipeIsUnavailableAndIsNotFollowed(string $location): void
    {
        // Given : la deuxième réponse porterait une recette parfaite, mais elle ne doit jamais être demandée
        $this->responses = [$this->redirect($location), $this->html(MarmitonPages::example())];

        // When / Then
        $this->assertFailure(MarmitonImportException::UNAVAILABLE, fn () => $this->read($this->url('https://www.marmiton.org/recettes/recette_x_22082.aspx')));
        $this->assertCount(1, $this->requests);
    }

    public function testARedirectionWithoutLocationIsUnavailable(): void
    {
        // Given
        $this->responses = [new MockResponse('', ['http_code' => 301]), $this->html(MarmitonPages::example())];

        // When / Then
        $this->assertFailure(MarmitonImportException::UNAVAILABLE, fn () => $this->read());
        $this->assertCount(1, $this->requests);
    }

    public function testTwoRedirectionsAreUnavailable(): void
    {
        // Given
        $this->responses = [
            $this->redirect('https://www.marmiton.org/recettes/recette_y_22082.aspx'),
            $this->redirect(self::EXPECTED_URL),
            $this->html(MarmitonPages::example()),
        ];

        // When / Then : la deuxième redirection n'est pas suivie
        $this->assertFailure(MarmitonImportException::UNAVAILABLE, fn () => $this->read($this->url('https://www.marmiton.org/recettes/recette_x_22082.aspx')));
        $this->assertCount(2, $this->requests);
    }

    public function testARedirectionToAMissingRecipeIsNotFound(): void
    {
        // Given
        $this->responses = [$this->redirect(self::EXPECTED_URL), $this->html('', 404)];

        // When / Then
        $this->assertFailure(MarmitonImportException::RECIPE_NOT_FOUND, fn () => $this->read($this->url('https://www.marmiton.org/recettes/recette_x_22082.aspx')));
    }

    public function testARedirectionToAnUnreadablePageIsUnreadable(): void
    {
        // Given
        $this->responses = [$this->redirect(self::EXPECTED_URL), $this->html(MarmitonPages::page([['@type' => 'WebSite']]))];

        // When / Then
        $this->assertFailure(MarmitonImportException::RECIPE_UNREADABLE, fn () => $this->read($this->url('https://www.marmiton.org/recettes/recette_x_22082.aspx')));
    }

    // ---------------------------------------------------------------- la taille et le type

    public function testABodyOverTwoMebibytesIsAbandonedWithoutReadingItAll(): void
    {
        // Given : un corps de 6,4 Mio en morceaux de 64 Kio ; on compte les morceaux que le client « reçoit »
        $received = 0;
        $chunk = str_repeat('a', 65536);
        $body = (static function () use ($chunk, &$received): \Generator {
            for ($i = 0; $i < 100; ++$i) {
                ++$received;
                yield $chunk;
            }
        })();
        $this->responses = [new MockResponse($body, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']])];

        // When / Then : le transfert est abandonné au morceau qui dépasse 2 Mio (le 33e est le dernier qui tient), le reste n'est jamais reçu
        $this->assertFailure(MarmitonImportException::UNAVAILABLE, fn () => $this->read());
        $this->assertGreaterThan(32, $received);
        $this->assertLessThanOrEqual(34, $received);
    }

    public function testABodyDeclaredOverTwoMebibytesIsRefusedFromItsHeaders(): void
    {
        // Given : la taille annoncée (et réelle) dépasse la borne d'un octet
        $body = str_repeat(' ', MarmitonHttpPageReader::MAX_BYTES + 1);
        $this->responses = [$this->html($body, 200, ['content-type' => 'text/html', 'content-length' => (string) strlen($body)])];

        // When / Then
        $this->assertFailure(MarmitonImportException::UNAVAILABLE, fn () => $this->read());
    }

    public function testABodyOfExactlyTwoMebibytesIsRead(): void
    {
        // Given : une page lisible, complétée par du texte jusqu'à 2 Mio pile
        $page = MarmitonPages::page([MarmitonPages::recipe()]);
        $padding = str_repeat(' ', MarmitonHttpPageReader::MAX_BYTES - strlen($page));
        $this->responses = [$this->html($page . $padding)];

        // When / Then
        $this->assertSame(MarmitonPages::EXAMPLE_TITLE, $this->read()->title);
    }

    public function testABodyOfOneByteOverTwoMebibytesIsAbandoned(): void
    {
        // Given
        $page = MarmitonPages::page([MarmitonPages::recipe()]);
        $padding = str_repeat(' ', MarmitonHttpPageReader::MAX_BYTES - strlen($page) + 1);
        $this->responses = [$this->html($page . $padding)];

        // When / Then
        $this->assertFailure(MarmitonImportException::UNAVAILABLE, fn () => $this->read());
    }

    /**
     * @return iterable<string, array{array<string, string|list<string>>}>
     */
    public static function notHtmlResponses(): iterable
    {
        yield 'du JSON' => [['content-type' => 'application/json']];
        yield 'du JSON-LD' => [['content-type' => 'application/ld+json']];
        yield 'du texte brut' => [['content-type' => 'text/plain']];
        yield 'du XML' => [['content-type' => 'application/xml']];
        yield 'du XHTML' => [['content-type' => 'application/xhtml+xml']];
        yield 'une image' => [['content-type' => 'image/png']];
        yield 'un type qui contient text/html' => [['content-type' => 'application/x-text/html']];
        yield 'aucun type' => [[]];
        yield 'un type vide' => [['content-type' => '']];
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    #[DataProvider('notHtmlResponses')]
    public function testAResponseWhichIsNotHtmlIsUnavailable(array $headers): void
    {
        // Given : le corps est une page parfaite, mais le type de contenu n'est pas le bon
        $this->responses = [$this->html(MarmitonPages::example(), 200, $headers)];

        // When / Then
        $this->assertFailure(MarmitonImportException::UNAVAILABLE, fn () => $this->read());
    }

    // ---------------------------------------------------------------- les pages sans recette lisible

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadablePages(): iterable
    {
        yield 'un JSON cassé' => [MarmitonPages::page(['{"@type": "Recipe", "name": '])];
        yield 'un bloc sans recette' => [MarmitonPages::page([['@type' => 'WebSite', 'name' => 'Marmiton']])];
        yield 'une recette sans ingrédient' => [MarmitonPages::page([MarmitonPages::recipe(['recipeIngredient' => []])])];
        yield 'une recette dont les ingrédients ne sont pas des textes' => [MarmitonPages::page([MarmitonPages::recipe(['recipeIngredient' => [1, 2]])])];
        yield 'une recette sans nom' => [MarmitonPages::page([MarmitonPages::recipe(['name' => ' '])])];
        yield 'une page sans script' => ['<html><body>Rien</body></html>'];
        yield 'une page vide' => [''];
    }

    #[DataProvider('unreadablePages')]
    public function testAPageWithoutAReadableRecipeIsUnreadable(string $html): void
    {
        // Given
        $this->responses = [$this->html($html)];

        // When / Then
        $this->assertFailure(MarmitonImportException::RECIPE_UNREADABLE, fn () => $this->read());
    }

    // ---------------------------------------------------------------- les adresses non publiques

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function refusedAddresses(): iterable
    {
        yield 'aucune adresse' => [[]];
        yield 'loopback' => [['127.0.0.1']];
        yield 'loopback, autre adresse du bloc' => [['127.1.2.3']];
        yield 'privée 10/8' => [['10.0.0.5']];
        yield 'privée 172.16/12' => [['172.16.0.1']];
        yield 'privée 172.31' => [['172.31.255.254']];
        yield 'privée 192.168/16' => [['192.168.1.20']];
        yield 'lien local et métadonnées du nuage' => [['169.254.169.254']];
        yield 'adresse partagée 100.64/10' => [['100.64.0.1']];
        yield 'adresse nulle' => [['0.0.0.0']];
        yield '0/8' => [['0.1.2.3']];
        yield 'multicast' => [['224.0.0.1']];
        yield 'diffusion' => [['255.255.255.255']];
        yield 'réservée 240/4' => [['240.0.0.1']];
        yield 'documentation 192.0.2/24' => [['192.0.2.10']];
        yield 'documentation 198.51.100/24' => [['198.51.100.10']];
        yield 'documentation 203.0.113/24' => [['203.0.113.10']];
        yield 'test de performance 198.18/15' => [['198.18.0.1']];
        yield 'protocoles IETF 192.0.0/24' => [['192.0.0.8']];
        yield 'une publique puis une privée' => [['93.184.216.34', '10.0.0.5']];
        yield 'une privée puis une publique' => [['192.168.0.1', '93.184.216.34']];
        yield 'une publique puis loopback' => [['93.184.216.34', '127.0.0.1']];
        yield 'ce qui n\'est pas une adresse' => [['www.marmiton.org']];
        yield 'une adresse IPv6' => [['2606:4700::6810:84e5']];
        yield 'loopback IPv6' => [['::1']];
        yield 'IPv4 écrite en IPv6' => [['::ffff:10.0.0.5']];
        yield 'une adresse mal écrite' => [['93.184.216']];
        yield 'une adresse avec un octet de trop' => [['93.184.216.256']];
        yield 'une adresse écrite en octal' => [['0177.0.0.1']];
        yield 'une adresse écrite en entier' => [['2130706433']];
        yield 'une chaîne vide' => [['']];
    }

    /**
     * @param list<string> $addresses
     */
    #[DataProvider('refusedAddresses')]
    public function testANonPublicAddressIsUnavailableAndNothingIsSent(array $addresses): void
    {
        // Given
        $this->resolver = new FixedHostAddressResolver($addresses);
        $this->responses = [$this->html(MarmitonPages::example())];

        // When / Then : aucune connexion n'est ouverte
        $this->assertFailure(MarmitonImportException::UNAVAILABLE, fn () => $this->read());
        $this->assertSame([], $this->requests);
        $this->assertSame(['www.marmiton.org'], $this->resolver->asked);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function acceptedAddresses(): iterable
    {
        yield 'une adresse publique' => [['93.184.216.34'], '93.184.216.34'];
        yield 'une autre adresse publique' => [['104.18.3.4'], '104.18.3.4'];
        yield 'plusieurs publiques : la première' => [['151.101.1.1', '104.18.3.4'], '151.101.1.1'];
        yield 'au bord de 172.16/12 (172.15)' => [['172.15.0.1'], '172.15.0.1'];
        yield 'au bord de 172.16/12 (172.32)' => [['172.32.0.1'], '172.32.0.1'];
        yield 'au bord de 100.64/10 (100.128)' => [['100.128.0.1'], '100.128.0.1'];
    }

    /**
     * @param list<string> $addresses
     */
    #[DataProvider('acceptedAddresses')]
    public function testOnlyPublicAddressesAreConnectedAndTheFirstOneIsImposed(array $addresses, string $expected): void
    {
        // Given
        $this->resolver = new FixedHostAddressResolver($addresses);
        $this->responses = [$this->html(MarmitonPages::example())];

        // When
        $this->read();

        // Then
        $this->assertSame(['www.marmiton.org' => $expected], $this->requests[0]['options']['resolve']);
    }

    // ---------------------------------------------------------------- le journal

    public function testTheLogGetsTheReasonButNeverThePageNorTheAddressPasted(): void
    {
        // Given
        $this->responses = [$this->html('PAGE-A-NE-JAMAIS-JOURNALISER', 503)];

        // When
        $this->assertFailure(MarmitonImportException::UNAVAILABLE, fn () => $this->read());

        // Then
        $logs = $this->logger->cleanLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('warning', $logs[0][0]);
        $this->assertSame(['reason' => 'réponse HTTP 503'], $logs[0][2]);
        $this->assertStringNotContainsString('PAGE-A-NE-JAMAIS', json_encode($logs, JSON_THROW_ON_ERROR));
    }

    public function testTheLoggerIsNotNeededWhenAllGoesWell(): void
    {
        // Given
        $this->responses = [$this->html(MarmitonPages::example())];

        // When
        $reader = new MarmitonHttpPageReader(
            new MockHttpClient($this->responses),
            $this->resolver,
            new MarmitonRecipeExtractor(),
            new NullLogger(),
        );

        // Then
        $this->assertSame(MarmitonPages::EXAMPLE_TITLE, $reader->read($this->url())->title);
        $this->assertSame([], $this->logger->cleanLogs());
    }
}
