<?php

namespace Repas\Voice\Application\Command\RefreshAccessGrant;


use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Repas\Voice\Application\Port\ActiveUserCommandPort;
use Repas\Voice\Application\Command\IssuedTokens;
use Repas\Voice\Domain\Interface\AccessGrantRepository;
use Repas\Voice\Domain\Interface\OpaqueTokenGenerator;
use Repas\Voice\Domain\Interface\VoiceLinkRepository;
use Repas\Voice\Domain\Model\AccessGrant;
use Repas\Voice\Domain\Model\OpaqueToken;
use Repas\Voice\Domain\Model\VoiceLink;
use Repas\Voice\Domain\Service\LinkRevoker;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Échange un jeton de rafraîchissement contre une nouvelle paire de jetons (ADR 0029 décision 6, RFC 6749 6), avec ROTATION comme le
 * refresh token du SPA (ADR 0015) : le jeton présenté est consommé et remplacé.
 *
 * Renvoie les jetons, ou `null` quand l'échange est refusé : l'appelant répond `invalid_grant`, sans dire pourquoi. Un refus ne lève
 * pas d'exception : il doit pouvoir révoquer la liaison, et le bus de commandes annulerait cette écriture si le handler échouait
 * (ADR 0015 §12).
 *
 * - jeton mal formé, inconnu, expiré, liaison retirée, compte inactif ou supprimé : refus, rien n'est écrit ;
 * - jeton DÉJÀ ÉCHANGÉ (rejeu) : refus, et la liaison est RÉVOQUÉE avec tous ses jetons. Il n'y a pas de délai de grâce : Alexa n'a
 *   qu'un client par liaison, un jeton vu deux fois est un jeton qui a fuité. Deux échanges simultanés du même jeton sont un rejeu : la
 *   consommation est ATOMIQUE, le second échange la perd.
 */
#[AsMessageHandler]
readonly class RefreshAccessGrantHandler
{
    public function __construct(
        private AccessGrantRepository $grantRepository,
        private VoiceLinkRepository $linkRepository,
        private LinkRevoker $linkRevoker,
        private ActiveUserCommandPort $activeUserChecker,
        private OpaqueTokenGenerator $tokenGenerator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshAccessGrantCommand $command): ?IssuedTokens
    {
        if (!OpaqueToken::isWellFormed($command->refreshToken)) {
            return null;
        }

        $now = $this->clock->now();
        $refreshHash = OpaqueToken::hash($command->refreshToken);
        $grant = $this->grantRepository->findOneByRefreshHash($refreshHash);
        if ($grant === null) {
            return null;
        }

        $link = $this->linkRepository->findOneById($grant->getLinkId());
        if ($link === null || $link->isRevoked() || $link->getUserId() !== $grant->getUserId()) {
            // Une liaison retirée n'a plus de jetons : si l'un traîne, il part
            $this->grantRepository->deleteByLink($grant->getLinkId());

            return null;
        }

        if ($grant->isRefreshUsed()) {
            return $this->replayed($link, $now);
        }

        if ($grant->isRefreshExpired($now) || !$this->activeUserChecker->isActive($grant->getUserId())) {
            return null;
        }

        if (!$this->grantRepository->consumeRefresh($refreshHash, $now)) {
            // Un autre échange du même jeton a gagné la course entre la lecture et l'écriture : deux présentations, donc un rejeu
            return $this->replayed($link, $now);
        }

        $accessToken = $this->tokenGenerator->generate();
        $refreshToken = $this->tokenGenerator->generate();
        $this->grantRepository->add($grant->rotate(OpaqueToken::hash($accessToken), OpaqueToken::hash($refreshToken), $now));
        $this->grantRepository->deleteExpiredOfLink($link->getId(), $now);

        return new IssuedTokens($accessToken, $refreshToken, AccessGrant::ACCESS_TTL_SECONDS);
    }

    private function replayed(VoiceLink $link, DateTimeImmutable $now): null
    {
        $this->logger->warning('Voice refresh token reuse detected, the link is revoked.', ['link' => $link->getId()]);
        $this->linkRevoker->revoke($link, $now);

        return null;
    }
}
