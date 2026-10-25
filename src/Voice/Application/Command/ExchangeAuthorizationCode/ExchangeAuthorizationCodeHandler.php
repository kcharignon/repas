<?php

namespace Repas\Voice\Application\Command\ExchangeAuthorizationCode;


use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Repas\Shared\Application\Interface\ActiveUserChecker;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Voice\Application\Command\IssuedTokens;
use Repas\Voice\Domain\Interface\AccessGrantRepository;
use Repas\Voice\Domain\Interface\AuthorizationCodeRepository;
use Repas\Voice\Domain\Interface\OpaqueTokenGenerator;
use Repas\Voice\Domain\Interface\VoiceLinkRepository;
use Repas\Voice\Domain\Model\AccessGrant;
use Repas\Voice\Domain\Model\OpaqueToken;
use Repas\Voice\Domain\Model\VoiceLink;
use Repas\Voice\Domain\Service\LinkRevoker;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Échange un code d'autorisation contre une paire de jetons (ADR 0029 décision 6, RFC 6749 4.1.3, RFC 7636 4.6).
 *
 * Renvoie les jetons, ou `null` quand l'échange est refusé : l'appelant répond `invalid_grant`, sans dire pourquoi. Un refus ne lève
 * pas d'exception : il doit pouvoir brûler le code ou révoquer une liaison, et le bus de commandes annulerait ces écritures si le
 * handler échouait (ADR 0015 §12).
 *
 * - code mal formé, inconnu, ou émis pour un autre client : refus, rien n'est écrit ;
 * - code DÉJÀ ÉCHANGÉ (rejeu) : refus, et la liaison créée par le premier échange est RÉVOQUÉE avec ses jetons (RFC 6749 4.1.2) ;
 * - code expiré : refus ;
 * - sinon, à partir d'ici le code est BRÛLÉ quoi qu'il arrive (usage unique, même pour un échange refusé) : adresse de retour qui n'est
 *   pas exactement celle de l'autorisation, vérificateur PKCE qui n'a pas pour SHA-256 le défi, compte inactif ou supprimé : refus.
 *   La consommation est ATOMIQUE : de deux échanges simultanés, un seul réussit.
 *
 * La liaison : le compte garde une seule liaison tant qu'Alexa ne l'a pas utilisée (identifiant d'utilisateur Alexa inconnu) : relier
 * de nouveau la réutilise, et ses anciens jetons meurent. Sinon, elle est créée ici (pas à l'émission du code : un code jamais échangé
 * ne laisse pas de liaison fantôme).
 */
#[AsMessageHandler]
readonly class ExchangeAuthorizationCodeHandler
{
    public function __construct(
        private AuthorizationCodeRepository $codeRepository,
        private VoiceLinkRepository $linkRepository,
        private AccessGrantRepository $grantRepository,
        private LinkRevoker $linkRevoker,
        private ActiveUserChecker $activeUserChecker,
        private OpaqueTokenGenerator $tokenGenerator,
        private UuidGenerator $uuidGenerator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExchangeAuthorizationCodeCommand $command): ?IssuedTokens
    {
        if (!OpaqueToken::isWellFormed($command->code)) {
            return null;
        }

        $now = $this->clock->now();
        $codeHash = OpaqueToken::hash($command->code);
        $code = $this->codeRepository->findOneByHash($codeHash);
        if ($code === null || !$code->belongsToClient($command->clientId)) {
            return null;
        }

        if ($code->isUsed()) {
            // Le code a déjà été échangé (ou brûlé par un échange refusé, qui n'a créé aucune liaison) : seule une liaison créée est révoquée
            $linkId = $code->getLinkId();
            $link = $linkId === null ? null : $this->linkRepository->findOneById($linkId);
            $this->logger->warning('Voice authorization code replayed.', ['link' => $link?->getId(), 'revoked' => $link !== null]);
            if ($link !== null) {
                $this->linkRevoker->revoke($link, $now);
            }

            return null;
        }

        if ($code->isExpired($now)) {
            return null;
        }

        if (!$code->accepts($command->redirectUri, $command->codeVerifier) || !$this->activeUserChecker->isActive($code->getUserId())) {
            // Brûlé : une tentative ratée ne laisse pas le code à celui qui l'aurait vu passer
            $this->codeRepository->consume($codeHash, $now, null);

            return null;
        }

        $link = $this->linkRepository->findAwaitingAlexaOfUser($code->getUserId());
        $isNewLink = $link === null;
        $link ??= VoiceLink::create($this->uuidGenerator->generate(), $code->getUserId(), $now);

        if (!$this->codeRepository->consume($codeHash, $now, $link->getId())) {
            // Un autre échange du même code a gagné la course entre la lecture et l'écriture
            return null;
        }

        if ($isNewLink) {
            $this->linkRepository->save($link);
        } else {
            // Relier de nouveau remplace les jetons de la liaison réutilisée
            $this->grantRepository->deleteByLink($link->getId());
        }

        $accessToken = $this->tokenGenerator->generate();
        $refreshToken = $this->tokenGenerator->generate();
        $this->grantRepository->add(AccessGrant::issue(
            OpaqueToken::hash($accessToken),
            OpaqueToken::hash($refreshToken),
            $link->getId(),
            $link->getUserId(),
            $now,
        ));

        return new IssuedTokens($accessToken, $refreshToken, AccessGrant::ACCESS_TTL_SECONDS);
    }
}
