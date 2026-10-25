<?php

namespace Repas\Voice\Application\Command\IssueAuthorizationCode;


/**
 * L'utilisateur connecté autorise Alexa à agir sur ses courses (ADR 0029 décision 6). Les champs sont ceux de la demande d'autorisation
 * d'Alexa, relayés par la page d'autorisation du front ; l'utilisateur vient du JWT.
 */
final readonly class IssueAuthorizationCodeCommand
{
    public function __construct(
        public string $userId,
        public string $clientId,
        public string $redirectUri,
        public string $state,
        public string $codeChallenge,
        public string $codeChallengeMethod,
    ) {
    }
}
