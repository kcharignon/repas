<?php

namespace Repas\Tests\Voice\Infrastructure\Security;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Alexa\AlexaTestClient;
use Repas\Voice\Domain\Interface\AlexaOAuthClient;
use Repas\Voice\Infrastructure\Security\EnvAlexaOAuthClient;

/**
 * Le client OAuth d'Alexa d'après la configuration (VOX-202) : identifiant, empreinte du secret, adresses de retour. Tout est refusé
 * tant que la configuration n'est pas complète et valable.
 */
class EnvAlexaOAuthClientTest extends TestCase
{
    public function testTheClientIsAuthenticatedByItsIdAndTheSecretWhoseSha256IsConfigured(): void
    {
        $client = $this->client();

        $this->assertTrue($client->authenticate(AlexaTestClient::CLIENT_ID, AlexaTestClient::SECRET));
        $this->assertTrue($client->isClientId(AlexaTestClient::CLIENT_ID));
    }

    #[DataProvider('wrongCredentials')]
    public function testAnythingElseIsRefused(string $clientId, string $secret): void
    {
        $this->assertFalse($this->client()->authenticate($clientId, $secret));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function wrongCredentials(): iterable
    {
        yield 'wrong secret' => [AlexaTestClient::CLIENT_ID, 'not-the-secret'];
        yield 'empty secret' => [AlexaTestClient::CLIENT_ID, ''];
        yield 'the hash itself as secret' => [AlexaTestClient::CLIENT_ID, AlexaTestClient::SECRET_HASH];
        yield 'secret with a trailing space' => [AlexaTestClient::CLIENT_ID, AlexaTestClient::SECRET . ' '];
        yield 'secret in another case' => [AlexaTestClient::CLIENT_ID, strtoupper(AlexaTestClient::SECRET)];
        yield 'wrong client id' => ['another-client', AlexaTestClient::SECRET];
        yield 'empty client id' => ['', AlexaTestClient::SECRET];
        yield 'client id in another case' => ['REPAS-ALEXA', AlexaTestClient::SECRET];
    }

    #[DataProvider('unusableHashes')]
    public function testWithoutAUsableHashEveryClientIsRefused(string $hash): void
    {
        // Given : l'empreinte est vide, d'une autre forme, ou celle d'un secret dont on connaît l'entrée
        $client = new EnvAlexaOAuthClient(AlexaTestClient::CLIENT_ID, $hash, AlexaTestClient::REDIRECT_URIS);

        // Then : même avec l'empreinte elle-même ou un secret vide, personne n'entre
        $this->assertFalse($client->authenticate(AlexaTestClient::CLIENT_ID, AlexaTestClient::SECRET));
        $this->assertFalse($client->authenticate(AlexaTestClient::CLIENT_ID, ''));
        $this->assertFalse($client->authenticate(AlexaTestClient::CLIENT_ID, $hash));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableHashes(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'too short' => [substr(AlexaTestClient::SECRET_HASH, 0, 63)];
        yield 'too long' => [AlexaTestClient::SECRET_HASH . '0'];
        yield 'not hexadecimal' => [str_repeat('z', 64)];
    }

    public function testTheHashIsReadWhateverItsCaseAndSurroundingSpaces(): void
    {
        $client = new EnvAlexaOAuthClient(AlexaTestClient::CLIENT_ID, '  ' . strtoupper(AlexaTestClient::SECRET_HASH) . "\n", AlexaTestClient::REDIRECT_URIS);

        $this->assertTrue($client->authenticate(AlexaTestClient::CLIENT_ID, AlexaTestClient::SECRET));
    }

    public function testWithoutAClientIdNoClientIsRecognizedNotEvenAnEmptyOne(): void
    {
        $client = new EnvAlexaOAuthClient('', AlexaTestClient::SECRET_HASH, AlexaTestClient::REDIRECT_URIS);

        $this->assertFalse($client->isClientId(''));
        $this->assertFalse($client->authenticate('', AlexaTestClient::SECRET));
    }

    public function testAClientIdLongerThanTheColumnIsRefused(): void
    {
        $client = new EnvAlexaOAuthClient(str_repeat('a', 101), AlexaTestClient::SECRET_HASH, AlexaTestClient::REDIRECT_URIS);

        $this->assertFalse($client->isClientId(str_repeat('a', 101)));
    }

    public function testOnlyTheConfiguredRedirectUrisAreAllowedAndExactly(): void
    {
        $client = $this->client();

        $this->assertTrue($client->isRedirectUriAllowed(AlexaTestClient::REDIRECT_URI));
        $this->assertTrue($client->isRedirectUriAllowed(AlexaTestClient::OTHER_REDIRECT_URI));
        foreach ([
            AlexaTestClient::REDIRECT_URI . '/',
            AlexaTestClient::REDIRECT_URI . '?x=1',
            AlexaTestClient::REDIRECT_URI . '#f',
            AlexaTestClient::REDIRECT_URI . ' ',
            strtoupper(AlexaTestClient::REDIRECT_URI),
            substr(AlexaTestClient::REDIRECT_URI, 0, -1),
            'https://alexa.example.test',
            'https://alexa.example.test/api/skill/link',
            'http://alexa.example.test/api/skill/link/TESTVENDOR',
            'https://evil.example.test/api/skill/link/TESTVENDOR',
            AlexaTestClient::REDIRECT_URI . ',' . AlexaTestClient::OTHER_REDIRECT_URI,
            '',
        ] as $other) {
            $this->assertFalse($client->isRedirectUriAllowed($other), $other);
        }
    }

    public function testTheListIsSplitOnCommasTrimmedAndKeepsOnlyHttpsAddressesWithoutFragment(): void
    {
        // Given : des espaces, un vide, une adresse http, une avec fragment, une sans schéma
        $client = new EnvAlexaOAuthClient(
            AlexaTestClient::CLIENT_ID,
            AlexaTestClient::SECRET_HASH,
            ' https://one.example.test/a , ,http://insecure.example.test/b,https://fragment.example.test/c#x,//no-scheme.example.test/d,https://two.example.test/e ',
        );

        // Then
        $this->assertTrue($client->isRedirectUriAllowed('https://one.example.test/a'));
        $this->assertTrue($client->isRedirectUriAllowed('https://two.example.test/e'));
        foreach (['http://insecure.example.test/b', 'https://fragment.example.test/c#x', 'https://fragment.example.test/c', '//no-scheme.example.test/d', ''] as $refused) {
            $this->assertFalse($client->isRedirectUriAllowed($refused), $refused);
        }
    }

    public function testWithoutAnyRedirectUriNoLinkIsPossible(): void
    {
        foreach (['', '   ', ',,'] as $configured) {
            $client = new EnvAlexaOAuthClient(AlexaTestClient::CLIENT_ID, AlexaTestClient::SECRET_HASH, $configured);

            $this->assertFalse($client->isRedirectUriAllowed(''), $configured);
            $this->assertFalse($client->isRedirectUriAllowed(AlexaTestClient::REDIRECT_URI), $configured);
        }
    }

    private function client(): AlexaOAuthClient
    {
        return new EnvAlexaOAuthClient(AlexaTestClient::CLIENT_ID, AlexaTestClient::SECRET_HASH, AlexaTestClient::REDIRECT_URIS);
    }
}
