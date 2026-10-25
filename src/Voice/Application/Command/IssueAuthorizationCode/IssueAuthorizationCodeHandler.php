<?php

namespace Repas\Voice\Application\Command\IssueAuthorizationCode;


use Psr\Clock\ClockInterface;
use Repas\Voice\Domain\Exception\VoiceException;
use Repas\Voice\Domain\Interface\AlexaOAuthClient;
use Repas\Voice\Domain\Interface\AuthorizationCodeRepository;
use Repas\Voice\Domain\Interface\OpaqueTokenGenerator;
use Repas\Voice\Domain\Model\AuthorizationCode;
use Repas\Voice\Domain\Model\OpaqueToken;
use Repas\Voice\Domain\Model\Pkce;
use Repas\Voice\Domain\Model\RedirectUrl;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Émet le code d'autorisation de la liaison de compte (ADR 0029 décision 6, RFC 6749 4.1.1 et RFC 7636) et renvoie l'adresse où
 * renvoyer l'utilisateur : l'adresse de retour AUTORISÉE suivie de `code` et `state`.
 *
 * Tout est refusé (`VoiceException::authorizationRefused()`, une seule réponse) sauf si TOUT ceci est vrai : le client est le nôtre,
 * l'adresse de retour est EXACTEMENT l'une de celles configurées, la méthode PKCE est `S256` (jamais `plain`), le défi a la forme d'un
 * SHA-256 en base64url, le `state` est une chaîne imprimable de 4096 caractères au plus. Rien n'est écrit en cas de refus.
 *
 * Le code (32 octets aléatoires) est valable 10 minutes, ne sert qu'une fois, et n'est JAMAIS gardé : la base reçoit son haché. Cette
 * commande renvoie donc une valeur (la seule occasion où le code existe en clair), comme `RotateRefreshToken` (ADR 0015 §12).
 */
#[AsMessageHandler]
readonly class IssueAuthorizationCodeHandler
{
    /**
     * `state` (RFC 6749 A.5) : des caractères imprimables, `MAX_STATE_LENGTH` au plus. Le `state` d'Alexa est un blob opaque et signé d'environ 1,1 Kio
     * (1100 caractères constatés à la première liaison réelle, 2026-10-25) : une limite de 1024 refusait toute liaison. L'adresse de retour qui le porte
     * reste très en deçà de la limite d'une adresse (8 Kio) des navigateurs et des serveurs d'Amazon.
     */
    public const int MAX_STATE_LENGTH = 4096;

    private const string STATE_PATTERN = '/\A[\x20-\x7E]{1,4096}\z/';

    public function __construct(
        private AuthorizationCodeRepository $codeRepository,
        private AlexaOAuthClient $oauthClient,
        private OpaqueTokenGenerator $tokenGenerator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws VoiceException `authorizationRefused()`
     */
    public function __invoke(IssueAuthorizationCodeCommand $command): string
    {
        if (
            !$this->oauthClient->isClientId($command->clientId)
            || !$this->oauthClient->isRedirectUriAllowed($command->redirectUri)
            || $command->codeChallengeMethod !== Pkce::METHOD_S256
            || !Pkce::isChallenge($command->codeChallenge)
            || preg_match(self::STATE_PATTERN, $command->state) !== 1
        ) {
            throw VoiceException::authorizationRefused();
        }

        $now = $this->clock->now();
        $code = $this->tokenGenerator->generate();

        // Les codes expirés du compte n'ont plus d'utilité (ni échange, ni détection de rejeu) : ils partent à chaque émission
        $this->codeRepository->deleteExpiredOfUser($command->userId, $now);
        $this->codeRepository->add(AuthorizationCode::issue(
            OpaqueToken::hash($code),
            $command->userId,
            $command->clientId,
            $command->redirectUri,
            $command->codeChallenge,
            $now,
        ));

        return RedirectUrl::build($command->redirectUri, $code, $command->state);
    }
}
