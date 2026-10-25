<?php

namespace Repas\Tests\Voice\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Voice\Domain\Model\CertificateChainUrl;

/**
 * L'adresse de la chaîne d'Amazon : la page « Host a custom skill as a web service » (https, hôte `s3.amazonaws.com`, chemin sous
 * `/echo.api/`, port 443 ou absent, adresse normalisée avant d'être jugée) et la prudence de ce dépôt (refuser dans le doute).
 */
class CertificateChainUrlTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}> l'en-tête reçu et l'adresse normalisée qu'on télécharge
     */
    public static function acceptedUrls(): iterable
    {
        $expected = 'https://s3.amazonaws.com/echo.api/echo-api-cert-6-ats.pem';

        yield 'l\'adresse d\'Amazon' => ['https://s3.amazonaws.com/echo.api/echo-api-cert-6-ats.pem', $expected];
        yield 'port 443' => ['https://s3.amazonaws.com:443/echo.api/echo-api-cert-6-ats.pem', $expected];
        yield 'protocole et hôte en majuscules' => ['HTTPS://S3.AmazonAWS.com/echo.api/echo-api-cert-6-ats.pem', $expected];
        yield 'barres doublées' => ['https://s3.amazonaws.com//echo.api///echo-api-cert-6-ats.pem', $expected];
        yield 'segment point' => ['https://s3.amazonaws.com/echo.api/./echo-api-cert-6-ats.pem', $expected];
        yield 'segment point-point qui reste dans echo.api' => ['https://s3.amazonaws.com/echo.api/sub/../echo-api-cert-6-ats.pem', $expected];
        yield 'point-point qui revient dans echo.api' => ['https://s3.amazonaws.com/other/../echo.api/echo-api-cert-6-ats.pem', $expected];
        yield 'fragment' => ['https://s3.amazonaws.com/echo.api/echo-api-cert-6-ats.pem#fragment', $expected];
        yield 'sous-dossier' => ['https://s3.amazonaws.com/echo.api/v2/cert.pem', 'https://s3.amazonaws.com/echo.api/v2/cert.pem'];
    }

    #[DataProvider('acceptedUrls')]
    public function testAnUrlOfAmazonIsAcceptedAndNormalized(string $header, string $expected): void
    {
        // When
        $url = CertificateChainUrl::tryFrom($header);

        // Then
        $this->assertNotNull($url);
        $this->assertSame($expected, $url->value());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedUrls(): iterable
    {
        yield 'vide' => [''];
        yield 'http' => ['http://s3.amazonaws.com/echo.api/cert.pem'];
        yield 'ftp' => ['ftp://s3.amazonaws.com/echo.api/cert.pem'];
        yield 'sans protocole' => ['//s3.amazonaws.com/echo.api/cert.pem'];
        yield 'sans protocole ni barres' => ['s3.amazonaws.com/echo.api/cert.pem'];
        yield 'autre hôte' => ['https://example.com/echo.api/cert.pem'];
        yield 'autre région de s3' => ['https://s3-eu-west-1.amazonaws.com/echo.api/cert.pem'];
        yield 'sous-domaine de s3' => ['https://evil.s3.amazonaws.com/echo.api/cert.pem'];
        yield 's3 en sous-domaine d\'un autre hôte' => ['https://s3.amazonaws.com.example.com/echo.api/cert.pem'];
        yield 'hôte avec point final' => ['https://s3.amazonaws.com./echo.api/cert.pem'];
        yield 'identifiants avant l\'hôte' => ['https://s3.amazonaws.com@example.com/echo.api/cert.pem'];
        yield 'identifiants avec s3 en nom' => ['https://s3.amazonaws.com:secret@example.com/echo.api/cert.pem'];
        yield 'antislash' => ['https://example.com\\@s3.amazonaws.com/echo.api/cert.pem'];
        yield 'port 8443' => ['https://s3.amazonaws.com:8443/echo.api/cert.pem'];
        yield 'port 80' => ['https://s3.amazonaws.com:80/echo.api/cert.pem'];
        yield 'port vide' => ['https://s3.amazonaws.com:/echo.api/cert.pem'];
        yield 'port 443 écrit 0443' => ['https://s3.amazonaws.com:0443/echo.api/cert.pem'];
        yield 'port 4430' => ['https://s3.amazonaws.com:4430/echo.api/cert.pem'];
        yield 'sans chemin' => ['https://s3.amazonaws.com'];
        yield 'chemin racine' => ['https://s3.amazonaws.com/'];
        yield 'echo.api sans fichier' => ['https://s3.amazonaws.com/echo.api'];
        yield 'echo.api avec barre finale' => ['https://s3.amazonaws.com/echo.api/'];
        yield 'dossier avec barre finale' => ['https://s3.amazonaws.com/echo.api/sub/'];
        yield 'autre dossier' => ['https://s3.amazonaws.com/other/cert.pem'];
        yield 'majuscules dans echo.api' => ['https://s3.amazonaws.com/Echo.api/cert.pem'];
        yield 'majuscules ECHO.API' => ['https://s3.amazonaws.com/ECHO.API/cert.pem'];
        yield 'préfixe seulement' => ['https://s3.amazonaws.com/echo.apiX/cert.pem'];
        yield 'echo.api en sous-dossier' => ['https://s3.amazonaws.com/other/echo.api/cert.pem'];
        yield 'point-point qui sort de echo.api' => ['https://s3.amazonaws.com/echo.api/../other/cert.pem'];
        yield 'point-point qui sort de la racine' => ['https://s3.amazonaws.com/../echo.api/cert.pem'];
        yield 'point-point final' => ['https://s3.amazonaws.com/echo.api/sub/..'];
        yield 'point final' => ['https://s3.amazonaws.com/echo.api/sub/.'];
        yield 'point-point encodé' => ['https://s3.amazonaws.com/echo.api/%2e%2e/other/cert.pem'];
        yield 'barre encodée' => ['https://s3.amazonaws.com/echo.api%2fcert.pem'];
        yield 'pourcentage dans le nom' => ['https://s3.amazonaws.com/echo.api/cert%20.pem'];
        yield 'requête' => ['https://s3.amazonaws.com/echo.api/cert.pem?x=1'];
        yield 'espace' => ['https://s3.amazonaws.com/echo.api/ cert.pem'];
        yield 'espace avant' => [' https://s3.amazonaws.com/echo.api/cert.pem'];
        yield 'espace après' => ['https://s3.amazonaws.com/echo.api/cert.pem '];
        yield 'saut de ligne final' => ["https://s3.amazonaws.com/echo.api/cert.pem\n"];
        yield 'injection d\'en-tête' => ["https://s3.amazonaws.com/echo.api/cert.pem\r\nHost: example.com"];
        yield 'octet nul' => ["https://s3.amazonaws.com/echo.api/cert.pem\0.evil"];
        yield 'lettres hors ASCII' => ['https://s3.amazonaws.com/echo.api/cértificat.pem'];
        yield 'hôte hors ASCII' => ['https://s3.amazonаws.com/echo.api/cert.pem'];
        yield 'trop longue' => ['https://s3.amazonaws.com/echo.api/' . str_repeat('a', 600) . '.pem'];
    }

    #[DataProvider('refusedUrls')]
    public function testAnyOtherUrlIsRefused(string $header): void
    {
        // When
        $url = CertificateChainUrl::tryFrom($header);

        // Then
        $this->assertNull($url);
    }
}
