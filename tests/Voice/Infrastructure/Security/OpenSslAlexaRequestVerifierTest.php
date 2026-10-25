<?php

namespace Repas\Tests\Voice\Infrastructure\Security;


use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Alexa\AlexaTestAuthority;
use Repas\Tests\Helper\Alexa\AlexaTestLeaf;
use Repas\Tests\Helper\Alexa\ChainFetcherDouble;
use Repas\Voice\Domain\Exception\VoiceException;
use Repas\Voice\Infrastructure\Security\OpenSslAlexaRequestVerifier;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;

/**
 * Le vérificateur de requêtes d'Alexa (VOX-203), avec de VRAIES signatures RSA et de VRAIS certificats nés dans le test : une
 * racine, un intermédiaire et des certificats « echo-api.amazon.com » de test ; le magasin de racines injecté ne contient que la
 * racine de test. Aucun accès à Amazon : la chaîne vient d'un double du port, l'horloge est une `MockClock`.
 *
 * Chaque refus est prouvé POUR LA BONNE RAISON : la catégorie (et au besoin la raison) que le vérificateur écrit au journal est
 * contrôlée, pour qu'un test ne passe pas parce qu'un autre contrôle a refusé à sa place.
 */
class OpenSslAlexaRequestVerifierTest extends TestCase
{
    private const string SKILL = 'amzn1.ask.skill.00000000-test-0000-0000-000000000001';
    private const string OTHER_SKILL = 'amzn1.ask.skill.00000000-other-0000-0000-000000000002';
    private const string CHAIN_URL = 'https://s3.amazonaws.com/echo.api/echo-api-cert-test.pem';
    private const string UTTERANCE = 'PHRASE-SECRETE-A-NE-JAMAIS-JOURNALISER';

    private static AlexaTestAuthority $root;
    private static AlexaTestAuthority $intermediate;
    private static AlexaTestAuthority $otherRoot;
    private static string $trustFile;
    private static AlexaTestLeaf $leaf;
    private static AlexaTestLeaf $strangerLeaf;

    private MockClock $clock;
    private BufferingLogger $logger;
    private ChainFetcherDouble $fetcher;

    public static function setUpBeforeClass(): void
    {
        self::$root = AlexaTestAuthority::root();
        self::$intermediate = self::$root->intermediate();
        self::$otherRoot = AlexaTestAuthority::root('Repas Test Other Root CA');
        self::$trustFile = self::$root->trustFile();
        self::$leaf = self::$intermediate->issue();
        self::$strangerLeaf = self::$otherRoot->issue();
    }

    protected function setUp(): void
    {
        // Une seconde ronde (les bornes de ±150 s se jugent sans fraction), 5 s EN AVANCE sur la machine : un certificat émis pendant
        // le test (sa période commence à la seconde de son émission) est ainsi toujours valable pour cette horloge.
        $this->clock = new MockClock(new DateTimeImmutable('@' . (time() + 5)));
        $this->logger = new BufferingLogger();
        $this->fetcher = new ChainFetcherDouble(self::$leaf->chainPem());
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    /**
     * @param list<string>|null $trustedRoots
     */
    private function verifier(string $skillId = self::SKILL, ?array $trustedRoots = null): OpenSslAlexaRequestVerifier
    {
        return new OpenSslAlexaRequestVerifier($this->fetcher, $this->clock, $this->logger, $skillId, $trustedRoots ?? [self::$trustFile]);
    }

    /**
     * Un corps d'Alexa synthétique, à l'heure de l'horloge de test (décalée de `$offset` secondes).
     */
    private function body(int $offset = 0, string $applicationId = self::SKILL, string $utterance = self::UTTERANCE): string
    {
        $at = $this->clock->now()->modify(sprintf('%+d seconds', $offset))->setTimezone(new DateTimeZone('UTC'));

        return json_encode([
            'version' => '1.0',
            'session' => [
                'new' => true,
                'sessionId' => 'amzn1.echo-api.session.00000000-test',
                'application' => ['applicationId' => $applicationId],
                'user' => ['userId' => 'amzn1.ask.account.TEST'],
            ],
            'context' => ['System' => [
                'application' => ['applicationId' => $applicationId],
                'user' => ['userId' => 'amzn1.ask.account.TEST'],
            ]],
            'request' => [
                'type' => 'IntentRequest',
                'requestId' => 'amzn1.echo-api.request.00000000-test',
                'timestamp' => $at->format('Y-m-d\TH:i:s\Z'),
                'locale' => 'fr-FR',
                'intent' => ['name' => 'AddItemIntent', 'slots' => ['item' => ['name' => 'item', 'value' => $utterance]]],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Le vérificateur refuse (`400 INVALID_ALEXA_REQUEST`), pour la raison attendue, sans rien écrire de la requête au journal.
     *
     * @param string|null $signature la signature à envoyer ; `null` : la bonne signature de ce corps par le certificat de test
     */
    private function assertRefused(
        string $body,
        ?string $signature,
        string $category,
        ?string $reason = null,
        string $url = self::CHAIN_URL,
        ?OpenSslAlexaRequestVerifier $verifier = null,
    ): void {
        try {
            ($verifier ?? $this->verifier())->verify($body, $url, $signature ?? self::$leaf->sign($body));
        } catch (VoiceException $exception) {
            $this->assertSame(VoiceException::INVALID_REQUEST, $exception->getMessage());
            $this->assertSame(400, $exception->getCode());
            $this->assertRefusalLogged($category, $reason, $body, $signature, $url);

            return;
        }

        $this->fail('La requête aurait dû être refusée.');
    }

    private function assertRefusalLogged(string $category, ?string $reason, string $body, ?string $signature, string $url): void
    {
        $logs = $this->logger->cleanLogs();
        $this->assertCount(1, $logs, 'un seul message par refus');
        $this->assertSame('warning', $logs[0][0]);
        $expected = ['category' => $category];
        if ($reason !== null) {
            $expected['reason'] = $reason;
        }
        $this->assertSame($expected, $logs[0][2], 'catégorie et raison du refus');

        $text = json_encode($logs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach ([self::UTTERANCE, self::SKILL, 'amzn1.ask.account', 's3.amazonaws.com'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        foreach ([$body, base64_encode($body), $signature ?? '', $url] as $received) {
            if (strlen($received) > 8) {
                $this->assertStringNotContainsString($received, $text);
            }
        }
    }

    // —— Ce qui est accepté ——

    public function testASignedRequestIsAccepted(): void
    {
        // Given
        $body = $this->body();

        // When
        $this->verifier()->verify($body, self::CHAIN_URL, self::$leaf->sign($body));

        // Then : la chaîne a été demandée une fois, à l'adresse normalisée, et rien n'a été journalisé
        $this->assertSame([self::CHAIN_URL], $this->fetcher->requested);
        $this->assertSame([], $this->logger->cleanLogs());
    }

    public function testACertificateIssuedDirectlyByTheRootIsAccepted(): void
    {
        // Given
        $leaf = self::$root->issue();
        $this->fetcher = new ChainFetcherDouble($leaf->chainPem());
        $body = $this->body();

        // When
        $this->verifier()->verify($body, self::CHAIN_URL, $leaf->sign($body));

        // Then
        $this->assertSame([self::CHAIN_URL], $this->fetcher->requested);
    }

    public function testTheChainMayAlsoCarryTheRootItself(): void
    {
        // Given : certaines chaînes publiées contiennent la racine ; elle ne sert pas d'ancre, celle du magasin si
        $this->fetcher = new ChainFetcherDouble(self::$leaf->chainPem() . self::$root->certificatePem);
        $body = $this->body();

        // When
        $this->verifier()->verify($body, self::CHAIN_URL, self::$leaf->sign($body));

        // Then
        $this->assertCount(1, $this->fetcher->requested);
    }

    public function testTheChainUrlIsNormalizedBeforeItIsFetched(): void
    {
        // Given
        $body = $this->body();

        // When
        $this->verifier()->verify($body, 'HTTPS://S3.AMAZONAWS.COM:443//echo.api/./other/../echo-api-cert-test.pem#fragment', self::$leaf->sign($body));

        // Then
        $this->assertSame([self::CHAIN_URL], $this->fetcher->requested);
    }

    public function testTheSkillIdentifierIsTrimmed(): void
    {
        // Given : la variable d'environnement est copiée avec un espace ou un saut de ligne
        $body = $this->body();

        // When
        $this->verifier(" " . self::SKILL . "\n")->verify($body, self::CHAIN_URL, self::$leaf->sign($body));

        // Then
        $this->assertCount(1, $this->fetcher->requested);
    }

    public function testTheIdentifierMayBeInTheContextAlone(): void
    {
        // Given : les requêtes sans session (AudioPlayer, etc.) n'ont que le contexte
        $data = json_decode($this->body(), true, 32, JSON_THROW_ON_ERROR);
        unset($data['session']);
        $body = json_encode($data, JSON_THROW_ON_ERROR);

        // When
        $this->verifier()->verify($body, self::CHAIN_URL, self::$leaf->sign($body));

        // Then
        $this->assertCount(1, $this->fetcher->requested);
    }

    public function testEchoApiAmongOtherNamesIsAccepted(): void
    {
        // Given
        $leaf = self::$intermediate->issue(['example.com', 'Echo-API.Amazon.com']);
        $this->fetcher = new ChainFetcherDouble($leaf->chainPem());
        $body = $this->body();

        // When
        $this->verifier()->verify($body, self::CHAIN_URL, $leaf->sign($body));

        // Then
        $this->assertCount(1, $this->fetcher->requested);
    }

    // —— Le corps et la signature ——

    public function testABodyChangedByOneByteIsRefused(): void
    {
        // Given : un corps signé, puis une lettre de la phrase changée : le JSON reste valide, l'horodatage et le skill aussi
        $signed = $this->body();
        $tampered = str_replace(self::UTTERANCE, 'X' . substr(self::UTTERANCE, 1), $signed);

        // When / Then
        $this->assertSame(strlen($signed), strlen($tampered));
        $this->assertNotSame($signed, $tampered);
        $this->assertRefused($tampered, self::$leaf->sign($signed), OpenSslAlexaRequestVerifier::SIGNATURE);
    }

    public function testABodyWithOneMoreByteIsRefused(): void
    {
        // Given : un simple espace final
        $signed = $this->body();

        // When / Then
        $this->assertRefused($signed . ' ', self::$leaf->sign($signed), OpenSslAlexaRequestVerifier::SIGNATURE);
        $this->assertRefused($signed . "\n", self::$leaf->sign($signed), OpenSslAlexaRequestVerifier::SIGNATURE);
    }

    public function testTheSignatureOfAnotherBodyIsRefused(): void
    {
        // Given
        $other = $this->body(utterance: 'une autre phrase');

        // When / Then
        $this->assertRefused($this->body(), self::$leaf->sign($other), OpenSslAlexaRequestVerifier::SIGNATURE);
    }

    public function testASignatureMadeByAnotherKeyIsRefused(): void
    {
        // Given : la chaîne est la bonne, la signature est celle d'un autre porteur de clé
        $body = $this->body();

        // When / Then
        $this->assertRefused($body, self::$strangerLeaf->sign($body), OpenSslAlexaRequestVerifier::SIGNATURE);
    }

    public function testARandomSignatureIsRefused(): void
    {
        // When / Then
        $this->assertRefused($this->body(), base64_encode(random_bytes(256)), OpenSslAlexaRequestVerifier::SIGNATURE);
    }

    public function testATruncatedOrExtendedSignatureIsRefused(): void
    {
        // Given
        $body = $this->body();
        $signature = base64_decode(self::$leaf->sign($body), true);

        // When / Then
        $this->assertRefused($body, base64_encode(substr($signature, 0, 128)), OpenSslAlexaRequestVerifier::SIGNATURE);
        $this->assertRefused($body, base64_encode($signature . 'x'), OpenSslAlexaRequestVerifier::SIGNATURE);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableSignatures(): iterable
    {
        yield 'absente' => [''];
        yield 'espaces' => ['   '];
        yield 'pas du base64' => ['ceci n\'est pas du base64 !!!'];
        yield 'base64 avec des caractères d\'URL' => ['abc-def_ghi'];
        yield 'base64 suivi d\'ordures' => ['AAAA=BBBB'];
        yield 'un seul caractère' => ['A'];
        yield 'trop longue' => [str_repeat('A', 2000)];
    }

    #[DataProvider('unusableSignatures')]
    public function testAMissingOrMalformedSignatureIsRefused(string $signature): void
    {
        // When / Then
        $this->assertRefused($this->body(), $signature, OpenSslAlexaRequestVerifier::SIGNATURE);
        $this->assertSame([], $this->fetcher->requested, 'rien n\'est téléchargé pour une signature inutilisable');
    }

    /**
     * @return iterable<string, array{string, string}> le corps et la catégorie du refus
     */
    public static function unreadableBodies(): iterable
    {
        yield 'vide' => ['', OpenSslAlexaRequestVerifier::BODY];
        yield 'pas du JSON' => ['ceci n\'est pas du JSON', OpenSslAlexaRequestVerifier::REQUEST];
        yield 'JSON tronqué' => ['{"request":{"timestamp":', OpenSslAlexaRequestVerifier::REQUEST];
        yield 'liste JSON' => ['[]', OpenSslAlexaRequestVerifier::REQUEST];
        yield 'null' => ['null', OpenSslAlexaRequestVerifier::REQUEST];
        yield 'objet vide' => ['{}', OpenSslAlexaRequestVerifier::REQUEST];
    }

    #[DataProvider('unreadableBodies')]
    public function testABodyThatIsNotJsonOfAlexaIsRefusedEvenWhenProperlySigned(string $body, string $category): void
    {
        // When / Then : la signature est bonne (celle du corps tel quel), le corps non
        $this->assertRefused($body, $body === '' ? 'AAAA' : self::$leaf->sign($body), $category);
        $this->assertSame([], $this->fetcher->requested);
    }

    public function testATooBigBodyIsRefused(): void
    {
        // Given
        $body = $this->body(utterance: str_repeat('a', OpenSslAlexaRequestVerifier::MAX_BODY_BYTES));

        // When / Then
        $this->assertRefused($body, self::$leaf->sign($body), OpenSslAlexaRequestVerifier::BODY);
        $this->assertSame([], $this->fetcher->requested);
    }

    // —— L'horodatage ——

    /**
     * @return iterable<string, array{int, bool}> l'âge de la requête en secondes (négatif : datée du futur) et si elle est acceptée
     */
    public static function ages(): iterable
    {
        yield 'à l\'heure' => [0, true];
        yield 'datée de 149 s avant' => [-149, true];
        yield 'datée de 149 s après' => [149, true];
        yield 'datée de 150 s avant' => [-150, true];
        yield 'datée de 150 s après' => [150, true];
        yield 'datée de 151 s avant' => [-151, false];
        yield 'datée de 151 s après' => [151, false];
        yield 'datée d\'une heure avant' => [-3600, false];
        yield 'datée d\'une heure après' => [3600, false];
    }

    #[DataProvider('ages')]
    public function testTheRequestTimestampMustBeWithin150SecondsOfTheClock(int $offset, bool $accepted): void
    {
        // Given
        $body = $this->body($offset);
        $signature = self::$leaf->sign($body);

        // When / Then
        if ($accepted) {
            $this->verifier()->verify($body, self::CHAIN_URL, $signature);
            $this->assertCount(1, $this->fetcher->requested);
        } else {
            $this->assertRefused($body, $signature, OpenSslAlexaRequestVerifier::TIMESTAMP);
            $this->assertSame([], $this->fetcher->requested, 'une requête périmée ne fait rien télécharger');
        }
    }

    public function testTheClockIsTheInjectedOne(): void
    {
        // Given : un corps signé à l'heure de l'horloge
        $body = $this->body();
        $signature = self::$leaf->sign($body);

        // When : l'horloge avance de 151 s
        $this->clock->sleep(151);

        // Then
        $this->assertRefused($body, $signature, OpenSslAlexaRequestVerifier::TIMESTAMP);
    }

    // —— L'identifiant du skill ——

    public function testARequestForAnotherSkillIsRefused(): void
    {
        // Given : signée par Amazon, mais pour un autre skill
        $body = $this->body(applicationId: self::OTHER_SKILL);

        // When / Then
        $this->assertRefused($body, self::$leaf->sign($body), OpenSslAlexaRequestVerifier::APPLICATION);
        $this->assertSame([], $this->fetcher->requested);
    }

    public function testARequestWithoutAnyApplicationIdIsRefused(): void
    {
        // Given
        $data = json_decode($this->body(), true, 32, JSON_THROW_ON_ERROR);
        unset($data['session'], $data['context']);
        $body = json_encode($data, JSON_THROW_ON_ERROR);

        // When / Then
        $this->assertRefused($body, self::$leaf->sign($body), OpenSslAlexaRequestVerifier::REQUEST);
    }

    public function testASessionAndAContextThatDisagreeAreRefused(): void
    {
        // Given : le contexte dit notre skill, la session un autre
        $data = json_decode($this->body(), true, 32, JSON_THROW_ON_ERROR);
        $data['session']['application']['applicationId'] = self::OTHER_SKILL;
        $body = json_encode($data, JSON_THROW_ON_ERROR);

        // When / Then
        $this->assertRefused($body, self::$leaf->sign($body), OpenSslAlexaRequestVerifier::APPLICATION);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unconfiguredSkillIds(): iterable
    {
        yield 'vide' => [''];
        yield 'espaces' => ['   '];
        yield 'saut de ligne' => ["\n"];
    }

    #[DataProvider('unconfiguredSkillIds')]
    public function testEverythingIsRefusedWhenTheSkillIsNotConfigured(string $skillId): void
    {
        // Given : un corps parfait, puis un corps dont l'identifiant de skill est lui aussi vide
        $body = $this->body();
        $emptyId = $this->body(applicationId: '');
        $verifier = $this->verifier($skillId);

        // When / Then
        $this->assertRefused($body, self::$leaf->sign($body), OpenSslAlexaRequestVerifier::CONFIGURATION, verifier: $verifier);
        $this->assertRefused($emptyId, self::$leaf->sign($emptyId), OpenSslAlexaRequestVerifier::CONFIGURATION, verifier: $verifier);
        $this->assertSame([], $this->fetcher->requested);
    }

    // —— L'adresse de la chaîne ——

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedChainUrls(): iterable
    {
        yield 'absente' => [''];
        yield 'http' => ['http://s3.amazonaws.com/echo.api/echo-api-cert-test.pem'];
        yield 'un autre hôte' => ['https://example.com/echo.api/echo-api-cert-test.pem'];
        yield 'un hôte qui commence comme celui d\'Amazon' => ['https://s3.amazonaws.com.example.com/echo.api/echo-api-cert-test.pem'];
        yield 'des identifiants avant l\'hôte' => ['https://s3.amazonaws.com@example.com/echo.api/echo-api-cert-test.pem'];
        yield 'un autre port' => ['https://s3.amazonaws.com:8443/echo.api/echo-api-cert-test.pem'];
        yield 'un chemin hors echo.api' => ['https://s3.amazonaws.com/other/echo-api-cert-test.pem'];
        yield 'un chemin qui sort de echo.api' => ['https://s3.amazonaws.com/echo.api/../other/echo-api-cert-test.pem'];
        yield 'echo.api en majuscules' => ['https://s3.amazonaws.com/Echo.api/echo-api-cert-test.pem'];
        yield 'un point-point encodé' => ['https://s3.amazonaws.com/echo.api/%2e%2e/other/echo-api-cert-test.pem'];
        yield 'une requête' => ['https://s3.amazonaws.com/echo.api/echo-api-cert-test.pem?x=1'];
    }

    #[DataProvider('refusedChainUrls')]
    public function testAChainUrlOutsideAmazonIsRefusedWithoutAnyDownload(string $url): void
    {
        // Given : un corps parfaitement signé
        $body = $this->body();

        // When / Then
        $this->assertRefused($body, self::$leaf->sign($body), OpenSslAlexaRequestVerifier::CHAIN_URL, url: $url);
        $this->assertSame([], $this->fetcher->requested, 'une adresse refusée n\'est jamais téléchargée');
    }

    public function testAChainThatCannotBeFetchedIsRefused(): void
    {
        // Given
        $this->fetcher = new ChainFetcherDouble(null);
        $body = $this->body();

        // When / Then
        $this->assertRefused($body, self::$leaf->sign($body), OpenSslAlexaRequestVerifier::CHAIN, 'double de test');
        $this->assertCount(1, $this->fetcher->requested);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableChains(): iterable
    {
        yield 'vide' => [''];
        yield 'du texte' => ['pas un certificat'];
        yield 'un certificat tronqué' => ["-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n"];
        yield 'un certificat illisible' => ["-----BEGIN CERTIFICATE-----\n" . str_repeat("AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA\n", 20) . "-----END CERTIFICATE-----\n"];
    }

    #[DataProvider('unreadableChains')]
    public function testAChainWithoutReadableCertificateIsRefused(string $chain): void
    {
        // Given
        $this->fetcher = new ChainFetcherDouble($chain);
        $body = $this->body();

        // When / Then
        $this->assertRefused($body, self::$leaf->sign($body), OpenSslAlexaRequestVerifier::CHAIN);
    }

    public function testAChainOfMoreThanEightCertificatesIsRefused(): void
    {
        // Given : neuf certificats bien lisibles
        $this->fetcher = new ChainFetcherDouble(str_repeat(self::$leaf->certificatePem, 9));
        $body = $this->body();

        // When / Then
        $this->assertRefused($body, self::$leaf->sign($body), OpenSslAlexaRequestVerifier::CHAIN);
    }

    // —— Le certificat ——

    public function testAnExpiredCertificateIsRefused(): void
    {
        // Given : un certificat qui a expiré à la seconde de sa création (days = 0), dans une chaîne par ailleurs bonne
        $expired = self::$intermediate->issue(days: 0);
        $this->fetcher = new ChainFetcherDouble($expired->chainPem());
        $body = $this->body();

        // When / Then
        $this->assertRefused($body, $expired->sign($body), OpenSslAlexaRequestVerifier::CERTIFICATE, 'période de validité');
    }

    public function testACertificateIsJudgedByTheInjectedClockAtTheEndOfItsValidity(): void
    {
        // Given : le certificat de test vaut 30 jours ; OpenSSL, lui, juge à l'heure réelle de la machine (donc le trouve valable)
        $notAfter = openssl_x509_parse(self::$leaf->certificatePem)['validTo_time_t'];

        // When : l'horloge injectée est une seconde avant la fin, puis à la fin
        $this->clock = new MockClock(new DateTimeImmutable('@' . ($notAfter - 1)));
        $body = $this->body();
        $this->verifier()->verify($body, self::CHAIN_URL, self::$leaf->sign($body));
        $this->clock = new MockClock(new DateTimeImmutable('@' . $notAfter));
        $body = $this->body();

        // Then
        $this->assertRefused($body, self::$leaf->sign($body), OpenSslAlexaRequestVerifier::CERTIFICATE, 'période de validité');
    }

    public function testACertificateNotYetValidAccordingToTheClockIsRefused(): void
    {
        // Given : l'horloge est la veille de la naissance du certificat
        $this->clock = new MockClock(new DateTimeImmutable('@' . (time() - 86400)));
        $body = $this->body();

        // When / Then
        $this->assertRefused($body, self::$leaf->sign($body), OpenSslAlexaRequestVerifier::CERTIFICATE, 'période de validité');
    }

    public function testAnIntermediateThatHasExpiredAccordingToTheClockIsRefused(): void
    {
        // Given : une feuille qui vit plus longtemps (4000 jours) que son intermédiaire (3650 jours)
        $longLived = self::$intermediate->issue(days: 4000);
        $this->fetcher = new ChainFetcherDouble($longLived->chainPem());
        $intermediateNotAfter = openssl_x509_parse(self::$intermediate->certificatePem)['validTo_time_t'];
        $this->clock = new MockClock(new DateTimeImmutable('@' . ($intermediateNotAfter - 1)));
        $body = $this->body();

        // When : valable une seconde avant la fin de l'intermédiaire
        $this->verifier()->verify($body, self::CHAIN_URL, $longLived->sign($body));
        $this->clock = new MockClock(new DateTimeImmutable('@' . $intermediateNotAfter));
        $body = $this->body();

        // Then : refusée à la fin de l'intermédiaire, alors que la feuille est encore valable
        $this->assertRefused($body, $longLived->sign($body), OpenSslAlexaRequestVerifier::CERTIFICATE, 'période de validité');
    }

    /**
     * @return iterable<string, array{?list<string>, string}> les noms alternatifs du certificat de signature et la raison du refus
     */
    public static function wrongNames(): iterable
    {
        yield 'sans extension de noms alternatifs (le CN seul ne suffit pas)' => [null, 'noms alternatifs absents'];
        yield 'un autre nom' => [['example.com'], 'nom alternatif absent'];
        yield 'un nom qui contient le bon' => [['echo-api.amazon.com.example.com'], 'nom alternatif absent'];
        yield 'un nom préfixé' => [['evil.echo-api.amazon.com'], 'nom alternatif absent'];
        yield 'un joker' => [['*.amazon.com'], 'nom alternatif absent'];
        yield 'un joker sur le sous-domaine' => [['*.echo-api.amazon.com'], 'nom alternatif absent'];
        yield 'le nom sans le sous-domaine' => [['amazon.com'], 'nom alternatif absent'];
    }

    /**
     * @param list<string>|null $names
     */
    #[DataProvider('wrongNames')]
    public function testACertificateThatDoesNotNameEchoApiIsRefused(?array $names, string $reason): void
    {
        // Given : un certificat bon par ailleurs (chaîne de confiance, validité, signature)
        $leaf = self::$intermediate->issue($names);
        $this->fetcher = new ChainFetcherDouble($leaf->chainPem());
        $body = $this->body();

        // When / Then
        $this->assertRefused($body, $leaf->sign($body), OpenSslAlexaRequestVerifier::CERTIFICATE, $reason);
    }

    public function testACertificateThatIsNotForTlsServersIsRefused(): void
    {
        // Given : un certificat qui nomme echo-api.amazon.com et que l'autorité a émis, mais pour des clients TLS seulement
        $leaf = self::$intermediate->issue(usage: 'clientAuth');
        $this->fetcher = new ChainFetcherDouble($leaf->chainPem());
        $body = $this->body();

        // When / Then : le certificat d'Amazon est un certificat de serveur
        $this->assertRefused($body, $leaf->sign($body), OpenSslAlexaRequestVerifier::CERTIFICATE, 'racine de confiance');
    }

    public function testACertificateWithAWeakKeyIsRefused(): void
    {
        // Given : un certificat de l'autorité de confiance, nommé comme il faut, mais dont la clé RSA ne fait que 1024 bits
        $leaf = self::$intermediate->issue(keyPem: AlexaTestAuthority::newPrivateKey(1024));
        $this->fetcher = new ChainFetcherDouble($leaf->chainPem());
        $body = $this->body();

        // When / Then
        $this->assertRefused($body, $leaf->sign($body), OpenSslAlexaRequestVerifier::CERTIFICATE, 'clé inacceptable');
    }

    public function testAnAuthorityCertificateCannotSignRequests(): void
    {
        // Given : un certificat d'autorité (CA:TRUE) qui nomme echo-api.amazon.com
        $leaf = self::$intermediate->issue(isAuthority: true);
        $this->fetcher = new ChainFetcherDouble($leaf->chainPem());
        $body = $this->body();

        // When / Then
        $this->assertRefused($body, $leaf->sign($body), OpenSslAlexaRequestVerifier::CERTIFICATE, 'autorité de certification');
    }

    // —— La chaîne de confiance ——

    public function testACertificateOfAnotherAuthorityIsRefused(): void
    {
        // Given : un certificat parfait en tout, mais d'une autorité que le magasin ne connaît pas
        $this->fetcher = new ChainFetcherDouble(self::$strangerLeaf->chainPem());
        $body = $this->body();

        // When / Then
        $this->assertRefused($body, self::$strangerLeaf->sign($body), OpenSslAlexaRequestVerifier::CERTIFICATE, 'racine de confiance');
    }

    public function testAnUnknownRootSentInTheChainIsNotATrustAnchor(): void
    {
        // Given : l'attaquant joint à sa chaîne la racine qui a signé son certificat
        $this->fetcher = new ChainFetcherDouble(self::$strangerLeaf->chainPem() . self::$otherRoot->certificatePem);
        $body = $this->body();

        // When / Then
        $this->assertRefused($body, self::$strangerLeaf->sign($body), OpenSslAlexaRequestVerifier::CERTIFICATE, 'racine de confiance');
    }

    public function testASelfSignedCertificateIsRefused(): void
    {
        // Given : un certificat qui nomme echo-api.amazon.com et ne se garantit que lui-même
        $selfSigned = AlexaTestAuthority::selfSignedLeaf();
        $this->fetcher = new ChainFetcherDouble($selfSigned->chainPem());
        $body = $this->body();

        // When / Then
        $this->assertRefused($body, $selfSigned->sign($body), OpenSslAlexaRequestVerifier::CERTIFICATE, 'racine de confiance');
    }

    public function testAChainMissingItsIntermediateIsRefused(): void
    {
        // Given : le certificat de signature seul, sans l'intermédiaire qui le relie à la racine de confiance
        $this->fetcher = new ChainFetcherDouble(self::$leaf->certificatePem);
        $body = $this->body();

        // When / Then
        $this->assertRefused($body, self::$leaf->sign($body), OpenSslAlexaRequestVerifier::CERTIFICATE, 'racine de confiance');
    }

    public function testAnIntermediateThatIsNotAnAuthorityBreaksTheChain(): void
    {
        // Given : un « intermédiaire » CA:FALSE, signé par la racine de confiance, qui signe un certificat echo-api
        $notAnAuthority = self::$root->intermediate('Repas Test Not A CA', isAuthority: false);
        $leaf = $notAnAuthority->issue();
        $this->fetcher = new ChainFetcherDouble($leaf->chainPem());
        $body = $this->body();

        // When / Then
        $this->assertRefused($body, $leaf->sign($body), OpenSslAlexaRequestVerifier::CERTIFICATE, 'racine de confiance');
    }

    public function testTheSystemTrustStoreDoesNotKnowTheTestAuthority(): void
    {
        // Given : le vérificateur de production (aucune racine injectée : le magasin du système)
        $body = $this->body();

        // When / Then : la chaîne de test, pourtant bonne pour le magasin de test, n'y est pas reconnue
        $this->assertRefused($body, self::$leaf->sign($body), OpenSslAlexaRequestVerifier::CERTIFICATE, 'racine de confiance', verifier: $this->verifier(trustedRoots: []));
    }

    // —— Hygiène ——

    public function testNoTemporaryFileIsLeftBehind(): void
    {
        // Given
        $before = glob(sys_get_temp_dir() . '/alexa-chain-*') ?: [];
        $body = $this->body();

        // When : une requête acceptée et une refusée par OpenSSL, toutes deux avec un intermédiaire
        $this->verifier()->verify($body, self::CHAIN_URL, self::$leaf->sign($body));
        $this->logger->cleanLogs();
        $this->assertRefused($body, base64_encode(random_bytes(256)), OpenSslAlexaRequestVerifier::SIGNATURE);

        // Then
        $this->assertSame($before, glob(sys_get_temp_dir() . '/alexa-chain-*') ?: []);
    }
}
