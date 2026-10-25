<?php

namespace Repas\Voice\Application\Command\RevokeVoiceLink;


use Psr\Clock\ClockInterface;
use Repas\Voice\Domain\Exception\VoiceException;
use Repas\Voice\Domain\Interface\VoiceLinkRepository;
use Repas\Voice\Domain\Service\LinkRevoker;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Retire une liaison avec Alexa et efface tous ses jetons (ADR 0029 décision 6) : le jeton d'accès qu'Alexa tenait ne résout plus rien,
 * son jeton de rafraîchissement n'échange plus rien. Idempotent : retirer une liaison déjà retirée ne change rien.
 *
 * Le droit de retirer (propriétaire seul) est jugé par le voter `VOICE_LINK_OWNER` à l'entrée de la route (`403`) ; ce cas d'usage le
 * redit pour ne jamais retirer la liaison d'un autre si un autre appelant l'oubliait.
 */
#[AsMessageHandler]
readonly class RevokeVoiceLinkHandler
{
    public function __construct(
        private VoiceLinkRepository $linkRepository,
        private LinkRevoker $linkRevoker,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws VoiceException `linkNotFound()` : la liaison n'existe pas ou n'est pas à cet utilisateur
     */
    public function __invoke(RevokeVoiceLinkCommand $command): void
    {
        $link = $this->linkRepository->findOneById($command->linkId);
        if ($link === null || $link->getUserId() !== $command->userId) {
            throw VoiceException::linkNotFound();
        }

        $this->linkRevoker->revoke($link, $this->clock->now());
    }
}
