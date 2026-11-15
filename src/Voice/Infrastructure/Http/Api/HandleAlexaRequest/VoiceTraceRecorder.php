<?php

namespace Repas\Voice\Infrastructure\Http\Api\HandleAlexaRequest;


use Psr\Log\LoggerInterface;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Voice\Application\Command\RecordVoiceTrace\RecordVoiceTraceCommand;
use Repas\Voice\Domain\Interface\VoiceTraceFeature;
use Repas\Voice\Domain\Model\AlexaResponse;
use Repas\Voice\Domain\Model\TracedFailure;
use Throwable;

/**
 * Garde un échange avec Alexa dans la trace (ADR 0029 décision 14), pour le webhook : après le calcul de la réponse, jamais avant.
 *
 * - **Interrupteur coupé (`ALEXA_TRACE_ENABLED`) : rien ne part, ni commande ni requête SQL** (pas même l'ouverture de la transaction du bus) ;
 * - **ne change jamais la réponse** : tout échec de la trace est attrapé et journalisé par sa CLASSE seulement, jamais son message ni le corps.
 *
 * Le webhook n'appelle ceci que pour une requête prouvée authentique : un corps qui ne vient pas d'Amazon n'est jamais gardé.
 */
final readonly class VoiceTraceRecorder
{
    public function __construct(
        private VoiceTraceFeature $feature,
        private CommandBusInterface $commandBus,
        private LoggerInterface $logger,
    ) {
    }

    public function record(mixed $body, AlexaResponse $response, ExchangeNotes $notes, int $durationMs, ?Throwable $failure): void
    {
        if (!$this->feature->isEnabled()) {
            return;
        }

        try {
            $this->commandBus->dispatch(new RecordVoiceTraceCommand(
                $notes->userId,
                $notes->linkId,
                $body,
                $response,
                $notes->outcome,
                $notes->decision,
                $durationMs,
                $failure === null ? null : TracedFailure::fromThrowable($failure),
            ));
        } catch (Throwable $exception) {
            $this->logger->warning('Trace de l\'échange avec Alexa impossible.', ['exception' => $exception::class]);
        }
    }
}
