<?php

namespace Repas\Voice\Domain\Interface;


/**
 * Le client OAuth de la liaison de compte (ADR 0029 décision 6) : Alexa, le seul. Sa configuration est celle du déploiement
 * (`ALEXA_OAUTH_CLIENT_ID`, `ALEXA_OAUTH_CLIENT_SECRET_HASH`, `ALEXA_REDIRECT_URIS`), pas du code.
 */
interface AlexaOAuthClient
{
    /**
     * C'est l'identifiant du client OAuth de ce déploiement (public, le même que dans la console Amazon).
     */
    public function isClientId(string $clientId): bool;

    /**
     * L'adresse de retour est EXACTEMENT l'une de celles autorisées (jamais un préfixe, jamais une comparaison approchée). Aucune
     * adresse autorisée : toutes refusées.
     */
    public function isRedirectUriAllowed(string $redirectUri): bool;

    /**
     * Ce client, avec ce secret, est le nôtre : le secret est comparé à temps constant à l'empreinte configurée. Aucune empreinte
     * configurée, ou invalide : tout client est refusé.
     */
    public function authenticate(string $clientId, string $clientSecret): bool;
}
