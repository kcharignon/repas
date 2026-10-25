<?php

namespace Repas\Tests\Voice\Infrastructure\Http;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Voice\Infrastructure\Http\Api\ExchangeOAuthToken\OAuthClientCredentials;
use Symfony\Component\HttpFoundation\Request;

/**
 * Comment Alexa présente les identifiants du client à l'échange de jetons (RFC 6749 2.3.1) : `Authorization: Basic` ou le corps du
 * formulaire, jamais l'adresse, jamais les deux.
 */
class OAuthClientCredentialsTest extends TestCase
{
    public function testBasicAuthorizationCarriesTheClientIdAndTheSecret(): void
    {
        $credentials = OAuthClientCredentials::fromRequest($this->request(['grant_type' => 'refresh_token'], basic: 'repas-alexa:s3cr3t'));

        $this->assertSame(['repas-alexa', 's3cr3t', true], [$credentials->clientId, $credentials->clientSecret, $credentials->viaHeader]);
    }

    public function testASecretThatContainsColonsKeepsThemAllAfterTheFirstOne(): void
    {
        $credentials = OAuthClientCredentials::fromRequest($this->request([], basic: 'repas-alexa:a:b:c'));

        $this->assertSame('a:b:c', $credentials->clientSecret);
    }

    public function testTheBodyCarriesTheClientIdAndTheSecret(): void
    {
        $credentials = OAuthClientCredentials::fromRequest($this->request(['client_id' => 'repas-alexa', 'client_secret' => 's3cr3t']));

        $this->assertSame(['repas-alexa', 's3cr3t', false], [$credentials->clientId, $credentials->clientSecret, $credentials->viaHeader]);
    }

    public function testTheSchemeNameIsNotCaseSensitive(): void
    {
        $request = $this->request([]);
        $request->headers->set('Authorization', 'basic ' . base64_encode('repas-alexa:s3cr3t'));

        $this->assertSame('s3cr3t', OAuthClientCredentials::fromRequest($request)->clientSecret);
    }

    public function testBasicAuthorizationMayRepeatTheSameClientIdInTheBody(): void
    {
        $credentials = OAuthClientCredentials::fromRequest($this->request(['client_id' => 'repas-alexa'], basic: 'repas-alexa:s3cr3t'));

        $this->assertSame('s3cr3t', $credentials->clientSecret);
    }

    #[DataProvider('unusable')]
    public function testAnythingElseGivesNoCredentials(Request $request): void
    {
        $this->assertNull(OAuthClientCredentials::fromRequest($request));
    }

    /**
     * @return iterable<string, array{Request}>
     */
    public static function unusable(): iterable
    {
        $make = static function (array $form, ?string $authorization = null, array $query = []): Request {
            $request = Request::create('/api/voice/oauth/token', 'POST', $form);
            $request->query->add($query);
            if ($authorization !== null) {
                $request->headers->set('Authorization', $authorization);
            }

            return $request;
        };

        yield 'nothing' => [$make([])];
        yield 'client id without secret' => [$make(['client_id' => 'repas-alexa'])];
        yield 'secret without client id' => [$make(['client_secret' => 's3cr3t'])];
        yield 'empty secret' => [$make(['client_id' => 'repas-alexa', 'client_secret' => ''])];
        yield 'credentials in the query string only' => [$make([], null, ['client_id' => 'repas-alexa', 'client_secret' => 's3cr3t'])];
        yield 'secret as an array' => [$make(['client_id' => 'repas-alexa', 'client_secret' => ['s3cr3t']])];
        yield 'header and body secret at once' => [$make(['client_secret' => 's3cr3t'], 'Basic ' . base64_encode('repas-alexa:s3cr3t'))];
        yield 'header client id differs from the body one' => [$make(['client_id' => 'other'], 'Basic ' . base64_encode('repas-alexa:s3cr3t'))];
        yield 'basic with invalid base64' => [$make([], 'Basic !!!not-base64!!!')];
        yield 'basic without colon' => [$make([], 'Basic ' . base64_encode('repas-alexa'))];
        yield 'basic with an empty client id' => [$make([], 'Basic ' . base64_encode(':s3cr3t'))];
        yield 'bearer scheme' => [$make(['client_id' => 'repas-alexa', 'client_secret' => 's3cr3t'], 'Bearer abc')];
        yield 'a huge secret' => [$make([], 'Basic ' . base64_encode('repas-alexa:' . str_repeat('a', 1025)))];
        yield 'a huge body secret' => [$make(['client_id' => 'repas-alexa', 'client_secret' => str_repeat('a', 1025)])];
    }

    public function testTheUrlDecodedVariantDecodesBothValues(): void
    {
        $credentials = OAuthClientCredentials::fromRequest($this->request([], basic: 'repas%2Dalexa:a%2Bb%3D'));

        $decoded = $credentials->urlDecoded();

        $this->assertSame(['repas%2Dalexa', 'a%2Bb%3D'], [$credentials->clientId, $credentials->clientSecret]);
        $this->assertSame(['repas-alexa', 'a+b=', true], [$decoded->clientId, $decoded->clientSecret, $decoded->viaHeader]);
    }

    /**
     * @param array<string, mixed> $form
     */
    private function request(array $form, ?string $basic = null): Request
    {
        $request = Request::create('/api/voice/oauth/token', 'POST', $form);
        if ($basic !== null) {
            $request->headers->set('Authorization', 'Basic ' . base64_encode($basic));
        }

        return $request;
    }
}
