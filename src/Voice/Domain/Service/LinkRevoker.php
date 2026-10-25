<?php

namespace Repas\Voice\Domain\Service;


use DateTimeImmutable;
use Repas\Voice\Domain\Interface\AccessGrantRepository;
use Repas\Voice\Domain\Interface\VoiceLinkRepository;
use Repas\Voice\Domain\Model\VoiceLink;

/**
 * Retire une liaison avec Alexa (ADR 0029 décision 6) : la liaison est marquée révoquée ET toutes ses paires de jetons sont effacées,
 * pour que plus aucun jeton ne serve. Une seule règle pour les trois façons de la retirer : l'utilisateur la supprime, un jeton de
 * rafraîchissement est rejoué, un code d'autorisation est rejoué.
 */
final readonly class LinkRevoker
{
    public function __construct(
        private VoiceLinkRepository $linkRepository,
        private AccessGrantRepository $grantRepository,
    ) {
    }

    public function revoke(VoiceLink $link, DateTimeImmutable $now): void
    {
        if (!$link->isRevoked()) {
            $this->linkRepository->save($link->revoke($now));
        }

        $this->grantRepository->deleteByLink($link->getId());
    }
}
