<?php

namespace Repas\Tests\Helper\Alexa;


/**
 * Le client OAuth d'Alexa tel que les tests le configurent (VOX-202) : des valeurs FACTICES, jamais un vrai secret ni une vraie adresse de
 * retour. `phpunit.dist.xml` et `.env.test` posent `ALEXA_OAUTH_CLIENT_SECRET_HASH` (l'empreinte SHA-256 de `SECRET`) et
 * `ALEXA_REDIRECT_URIS` (`REDIRECT_URI` et `OTHER_REDIRECT_URI`) ; `AlexaTestClientTest` garde ces valeurs d'accord avec ces constantes.
 */
final class AlexaTestClient
{
    public const string CLIENT_ID = 'repas-alexa';
    public const string SECRET = 'test-only-alexa-client-secret-not-a-real-one';
    public const string SECRET_HASH = 'e5cea9a0b4d40315990eb02d0ae04f5c36c4131aa251d9e0d4e4e731161c9f74';
    public const string REDIRECT_URI = 'https://alexa.example.test/api/skill/link/TESTVENDOR';
    public const string OTHER_REDIRECT_URI = 'https://alexa-eu.example.test/api/skill/link/TESTVENDOR';
    public const string REDIRECT_URIS = self::REDIRECT_URI . ',' . self::OTHER_REDIRECT_URI;

    private function __construct()
    {
    }

    /**
     * Un secret opaque de la bonne forme (43 caractères base64url), déterministe : `token('code-1')`. Le `$seed` n'a que des lettres,
     * chiffres, `-` et `_`.
     */
    public static function token(string $seed): string
    {
        return substr(str_pad($seed . '-', 43, 'x'), 0, 43);
    }

    /**
     * Un vérificateur PKCE (43 à 128 caractères non réservés) déterministe, et le défi S256 qui lui correspond.
     *
     * @return array{verifier: string, challenge: string}
     */
    public static function pkce(string $seed = 'a'): array
    {
        $verifier = substr(str_repeat($seed . 'V3rifier-', 20), 0, 64);

        return ['verifier' => $verifier, 'challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    }
}
