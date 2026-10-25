<?php

namespace Repas\Voice\Infrastructure\Http\Api\ExchangeOAuthToken;


use Symfony\Component\HttpFoundation\Request;

/**
 * Les identifiants du client OAuth tels qu'Alexa les présente à l'échange de jetons (RFC 6749 2.3.1). Amazon accepte deux façons, et
 * deux seulement : `Authorization: Basic` ou `client_id` et `client_secret` dans le corps du formulaire. Ni l'une ni l'autre, les deux
 * à la fois (le corps ne répète alors que `client_id`, identique), ou une valeur démesurée : pas d'identifiants, le client est refusé.
 * Jamais l'adresse (`$request->query`) : un secret dans l'adresse part dans les journaux.
 */
final readonly class OAuthClientCredentials
{
    /** Au-delà, ce n'est pas un identifiant de client : on ne hache pas un corps entier. */
    private const int MAX_LENGTH = 1024;

    private function __construct(
        public string $clientId,
        public string $clientSecret,
        /** Présentés par `Authorization: Basic` (et non dans le corps) : le refus ajoute alors `WWW-Authenticate` (RFC 6749 5.2). */
        public bool $viaHeader,
    ) {
    }

    public static function fromRequest(Request $request): ?self
    {
        $form = $request->request->all();
        $bodyClientId = self::text($form['client_id'] ?? null);
        $bodySecret = self::text($form['client_secret'] ?? null);

        $header = $request->headers->get('Authorization');
        if ($header !== null && preg_match('/\ABasic +([A-Za-z0-9+\/]+={0,2})\z/i', trim($header), $matches) === 1) {
            $decoded = base64_decode($matches[1], true);
            if ($decoded === false || !str_contains($decoded, ':') || $bodySecret !== null) {
                return null;
            }

            [$clientId, $clientSecret] = explode(':', $decoded, 2);
            if ($clientId === '' || strlen($clientId) > self::MAX_LENGTH || strlen($clientSecret) > self::MAX_LENGTH || ($bodyClientId !== null && $bodyClientId !== $clientId)) {
                return null;
            }

            return new self($clientId, $clientSecret, true);
        }

        if ($header !== null && $header !== '') {
            // Une autre méthode d'authentification (Bearer…) : Alexa n'en utilise pas, et la mélanger au corps est interdit
            return null;
        }

        return $bodyClientId !== null && $bodySecret !== null ? new self($bodyClientId, $bodySecret, false) : null;
    }

    /**
     * La variante décodée comme un formulaire (RFC 6749 2.3.1 : identifiant et secret sont encodés `application/x-www-form-urlencoded`
     * avant le Base64). Certains clients ne le font pas : l'appelant essaie d'abord les valeurs brutes, puis celles-ci.
     */
    public function urlDecoded(): self
    {
        return new self(urldecode($this->clientId), urldecode($this->clientSecret), $this->viaHeader);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= self::MAX_LENGTH ? $value : null;
    }
}
