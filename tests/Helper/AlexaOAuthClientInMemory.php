<?php

namespace Repas\Tests\Helper;


use Repas\Tests\Helper\Alexa\AlexaTestClient;
use Repas\Voice\Domain\Interface\AlexaOAuthClient;

/**
 * Le client OAuth d'Alexa des tests unitaires de handlers : le client et les adresses de retour de `AlexaTestClient`, sans environnement
 * (un handler ne touche pas l'infrastructure). L'authentification réelle (empreinte, temps constant) se teste dans `EnvAlexaOAuthClientTest`.
 */
class AlexaOAuthClientInMemory implements AlexaOAuthClient
{
    public function isClientId(string $clientId): bool
    {
        return $clientId === AlexaTestClient::CLIENT_ID;
    }

    public function isRedirectUriAllowed(string $redirectUri): bool
    {
        return in_array($redirectUri, [AlexaTestClient::REDIRECT_URI, AlexaTestClient::OTHER_REDIRECT_URI], true);
    }

    public function authenticate(string $clientId, string $clientSecret): bool
    {
        return $clientId === AlexaTestClient::CLIENT_ID && $clientSecret === AlexaTestClient::SECRET;
    }
}
