<?php

namespace Repas\Voice\Infrastructure\Http\Api\IssueAuthorizationCode;


use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * La demande d'autorisation d'Alexa (paramètres de l'adresse d'autorisation que reçoit la page `/alexa/lier`), relayée telle quelle. Seule
 * la forme est jugée ici (présence, longueur) ; le fond (client, adresse de retour, méthode PKCE) l'est par le cas d'usage.
 */
final readonly class IssueAuthorizationCodePayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        #[OA\Property(description: "Paramètre `client_id` de la demande d'Alexa : l'identifiant du client OAuth (`ALEXA_OAUTH_CLIENT_ID`)", example: 'repas-alexa')]
        public string $clientId,
        #[Assert\NotBlank]
        #[Assert\Length(max: 2048)]
        #[OA\Property(description: "Paramètre `redirect_uri` de la demande d'Alexa : doit être EXACTEMENT l'une des adresses autorisées (`ALEXA_REDIRECT_URIS`)", example: 'https://pitangui.amazon.com/api/skill/link/M2AAAAAAAAAAAA')]
        public string $redirectUri,
        #[Assert\NotBlank]
        #[Assert\Length(max: 1024)]
        #[OA\Property(description: "Paramètre `state` de la demande d'Alexa, rendu tel quel dans l'adresse de retour (1024 caractères imprimables au plus)", example: 'xyz123')]
        public string $state,
        #[Assert\NotBlank]
        #[Assert\Length(max: 128)]
        #[OA\Property(description: 'Paramètre `code_challenge` : SHA-256 du vérificateur en base64url sans remplissage (43 caractères)', example: 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM')]
        public string $codeChallenge,
        #[Assert\NotBlank]
        #[Assert\Length(max: 16)]
        #[OA\Property(description: 'Paramètre `code_challenge_method` : `S256` seul est accepté (`plain` est refusé)', example: 'S256')]
        public string $codeChallengeMethod,
    ) {
    }
}
