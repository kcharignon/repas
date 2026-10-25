<?php

namespace Repas\Voice\Application\Command\RecordVoiceLinkUse;


use Psr\Clock\ClockInterface;
use Repas\Voice\Domain\Interface\VoiceLinkRepository;
use Repas\Voice\Domain\Model\VoiceLink;
use Repas\Voice\Domain\Service\LinkRevoker;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Suit une liaison avec Alexa à chaque requête authentique (ADR 0029 décisions 6 et 10, VOX-204) :
 *
 * - **la date de dernier usage** (`last_used_at`, montrée à l'utilisateur dans « Moi ») s'écrit au plus toutes les
 *   `VoiceLink::USE_RECORD_INTERVAL_SECONDS` par liaison, d'après la valeur déjà lue en résolvant le jeton : quand elle est récente,
 *   le cas d'usage ne fait AUCUNE requête SQL. Quand il écrit, c'est une seule instruction ciblée (`recordUse`), jamais `save()` ;
 * - **l'identifiant d'utilisateur Alexa** est posé la première fois qu'on le voit (liaison qui n'en a pas), jamais changé ensuite. Cet
 *   identifiant ne sert qu'à reconnaître un même Echo relié deux fois : les AUTRES liaisons du même compte qui portent le même
 *   identifiant sont révoquées, avec leurs jetons (une liaison par Echo et par compte, VOX-202 laissait ce ménage à ce ticket).
 *   La pose est conditionnelle en base : de deux requêtes simultanées une seule la fait, une seule révoque.
 *
 * L'appelant n'en dépend pas : un échec du suivi ne fait jamais échouer la réponse vocale (le webhook l'attrape).
 */
#[AsMessageHandler]
readonly class RecordVoiceLinkUseHandler
{
    public function __construct(
        private VoiceLinkRepository $linkRepository,
        private LinkRevoker $linkRevoker,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordVoiceLinkUseCommand $command): void
    {
        $now = $this->clock->now();
        // L'identifiant à poser : seulement si la liaison n'en a pas encore
        $alexaUserId = $command->knownAlexaUserId === null ? $command->alexaUserId : null;
        $recordUse = VoiceLink::isUseRecordDue($command->lastUsedAt, $now);
        if ($alexaUserId === null && !$recordUse) {
            return;
        }

        if ($alexaUserId !== null && $this->linkRepository->identifyAlexaUser($command->linkId, $alexaUserId)) {
            foreach ($this->linkRepository->findOthersOfAlexaUser($command->userId, $alexaUserId, $command->linkId) as $other) {
                $this->linkRevoker->revoke($other, $now);
            }
        }

        if ($recordUse) {
            $this->linkRepository->recordUse($command->linkId, $now);
        }
    }
}
