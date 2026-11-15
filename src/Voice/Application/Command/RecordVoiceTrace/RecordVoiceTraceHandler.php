<?php

namespace Repas\Voice\Application\Command\RecordVoiceTrace;


use Psr\Clock\ClockInterface;
use Repas\Voice\Domain\Interface\VoiceTraceFeature;
use Repas\Voice\Domain\Interface\VoiceTraceRepository;
use Repas\Voice\Domain\Model\VoiceTrace;
use Repas\Voice\Domain\Service\AlexaTraceSanitizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Garde un échange avec Alexa dans la trace (ADR 0029 décision 14, amendement du 2026-11-15, phase de test) : la requête assainie par liste
 * blanche (`AlexaTraceSanitizer` : jamais le jeton d'accès ni un identifiant d'utilisateur ou d'appareil), la réponse bornée, l'issue, la durée et la
 * panne éventuelle, puis les 1 000 plus récents seulement (`VoiceTraceRepository::add`).
 *
 * **Interrupteur coupé (`ALEXA_TRACE_ENABLED`) : rien n'est lu ni écrit.** L'appelant ne dépend pas du résultat : le webhook attrape tout échec de
 * la trace, qui ne change jamais ce qu'Alexa dit.
 */
#[AsMessageHandler]
readonly class RecordVoiceTraceHandler
{
    public function __construct(
        private VoiceTraceRepository $traceRepository,
        private VoiceTraceFeature $feature,
        private AlexaTraceSanitizer $sanitizer,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordVoiceTraceCommand $command): void
    {
        if (!$this->feature->isEnabled()) {
            return;
        }

        $this->traceRepository->add(VoiceTrace::record(
            $this->clock->now(),
            $command->userId,
            $command->linkId,
            $this->sanitizer->request($command->body),
            $this->sanitizer->response($command->response->toArray()),
            $command->outcome,
            $command->decision,
            $command->durationMs,
            $command->failure,
        ));
    }
}
