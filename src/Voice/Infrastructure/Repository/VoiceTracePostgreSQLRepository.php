<?php

namespace Repas\Voice\Infrastructure\Repository;


use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;
use Repas\Voice\Domain\Interface\VoiceTraceRepository;
use Repas\Voice\Domain\Model\VoiceTrace;
use Repas\Voice\Infrastructure\Entity\VoiceTrace as VoiceTraceEntity;

/**
 * Écriture de la trace en SQL direct (ADR 0029 décision 14) : DEUX instructions, quel que soit le nombre de lignes, dans la transaction du bus : une
 * insertion, puis la suppression de tout ce qui est plus ancien que le `RETENTION`-ième échange le plus récent. Le numéro est croissant (colonne
 * d'identité) : « plus récent » veut dire « numéro plus grand ». Jamais l'ORM : aucune entité n'entre dans l'identity map.
 */
readonly class VoiceTracePostgreSQLRepository extends PostgreSQLRepository implements VoiceTraceRepository
{
    private const int JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, VoiceTraceEntity::class);
    }

    public function add(VoiceTrace $trace): void
    {
        $connection = $this->entityManager->getConnection();

        $connection->executeStatement(
            'INSERT INTO voice_trace (created_at, user_id, link_id, request_type, intent, session_new, outcome, decision, duration_ms, request, response, failure) '
            . 'VALUES (:createdAt, :userId, :linkId, :requestType, :intent, :sessionNew, :outcome, :decision, :durationMs, '
            . 'CAST(:request AS JSONB), CAST(:response AS JSONB), CAST(:failure AS JSONB))',
            [
                // Doctrine relit les dates dans le fuseau par défaut de PHP : on écrit dans le même, quel que soit celui de `createdAt`
                'createdAt' => $trace->createdAt->setTimezone(new DateTimeZone(date_default_timezone_get())),
                'userId' => $trace->userId,
                'linkId' => $trace->linkId,
                'requestType' => $trace->requestType,
                'intent' => $trace->intent,
                'sessionNew' => $trace->sessionNew,
                'outcome' => $trace->outcome->value,
                'decision' => $trace->decision,
                'durationMs' => $trace->durationMs,
                'request' => json_encode($trace->request, self::JSON_FLAGS),
                'response' => json_encode($trace->response, self::JSON_FLAGS),
                'failure' => $trace->failure === null ? null : json_encode($trace->failure->toArray(), self::JSON_FLAGS),
            ],
            [
                'createdAt' => Types::DATETIME_IMMUTABLE,
                'sessionNew' => Types::BOOLEAN,
                'durationMs' => Types::INTEGER,
            ],
        );

        // Sans le `RETENTION`-ième échange le plus récent (moins de lignes que cela) la sous-requête est vide, rien n'est supprimé. La constante est un
        // entier du code, jamais une valeur reçue : elle s'écrit dans le texte de la requête.
        $connection->executeStatement(sprintf(
            'DELETE FROM voice_trace WHERE id < (SELECT id FROM voice_trace ORDER BY id DESC OFFSET %d LIMIT 1)',
            VoiceTrace::RETENTION - 1,
        ));
    }
}
