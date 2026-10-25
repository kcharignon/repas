<?php

namespace Repas\Voice\Application\Command\ExchangeAuthorizationCode;


/**
 * Alexa échange le code d'autorisation contre des jetons (RFC 6749 4.1.3). Le client est déjà AUTHENTIFIÉ par la couche HTTP : `clientId`
 * est son identifiant, jamais son secret, qui ne traverse pas le bus.
 */
final readonly class ExchangeAuthorizationCodeCommand
{
    public function __construct(
        public string $clientId,
        public string $code,
        public string $redirectUri,
        public string $codeVerifier,
    ) {
    }
}
