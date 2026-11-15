<?php

namespace Repas\Voice\Infrastructure\Loader;


use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Repas\Voice\Application\Loader\VoiceTraceLoader;
use Repas\Voice\Application\Query\GetVoiceTrace\VoiceTraceDetailDTO;
use Repas\Voice\Application\Query\ListVoiceTraces\VoiceTraceSummaryDTO;
use Repas\Voice\Domain\Model\TraceOutcome;

/**
 * La trace des échanges avec Alexa en SQL (ADR 0029 décision 14), en lecture seule : une seule requête par lecture, quel que soit le nombre de lignes. Le
 * texte dit par Alexa et le résumé des créneaux sont tirés du JSONB ici, une fois, pour que la commande de console n'ait qu'à les écrire.
 */
final readonly class VoiceTracePostgreSQLLoader implements VoiceTraceLoader
{
    /** Les colonnes d'une ligne de résumé : le texte dit, les créneaux et l'étape du dialogue sont extraits du JSONB par la base. */
    private const string SUMMARY_COLUMNS = 'id, created_at, request_type, intent, session_new, outcome, decision, duration_ms, '
        . "response #>> '{response,outputSpeech,text}' AS said, "
        . "request #>> '{request,intent,slots}' AS slots, "
        . "request #>> '{session,attributes,dialogue,step}' AS dialogue_step, "
        . "failure ->> 'class' AS failure_class";

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function recent(int $limit, ?string $intent, bool $failedOnly, ?DateTimeImmutable $since): array
    {
        $conditions = [];
        $parameters = [];
        $types = [];
        if ($intent !== null) {
            $conditions[] = 'intent = :intent';
            $parameters['intent'] = $intent;
        }
        if ($failedOnly) {
            $conditions[] = 'outcome = :failed';
            $parameters['failed'] = TraceOutcome::FAILED->value;
        }
        if ($since !== null) {
            $conditions[] = 'created_at >= :since';
            // Doctrine relit les dates dans le fuseau par défaut de PHP : on compare dans le même
            $parameters['since'] = $since->setTimezone(new DateTimeZone(date_default_timezone_get()));
            $types['since'] = Types::DATETIME_IMMUTABLE;
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT ' . self::SUMMARY_COLUMNS . ' FROM voice_trace'
            . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions))
            // Le numéro est croissant : le plus grand est le plus récent
            . ' ORDER BY id DESC LIMIT ' . max(1, min(self::MAX_LIMIT, $limit)),
            $parameters,
            $types,
        );

        return array_map($this->summary(...), $rows);
    }

    public function one(int $id): ?VoiceTraceDetailDTO
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT ' . self::SUMMARY_COLUMNS . ', user_id, link_id, request, response, failure FROM voice_trace WHERE id = :id',
            ['id' => $id],
        );
        if ($row === false) {
            return null;
        }

        return new VoiceTraceDetailDTO(
            $this->summary($row),
            $row['user_id'],
            $row['link_id'],
            $this->decodedObject($row['request']),
            $this->decodedObject($row['response']),
            $row['failure'] === null ? null : $this->decodedObject($row['failure']),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function summary(array $row): VoiceTraceSummaryDTO
    {
        return new VoiceTraceSummaryDTO(
            (int) $row['id'],
            new DateTimeImmutable((string) $row['created_at']),
            (string) $row['request_type'],
            $row['intent'],
            $row['session_new'] === null ? null : (bool) $row['session_new'],
            (string) $row['outcome'],
            $row['decision'],
            (int) $row['duration_ms'],
            $row['said'],
            $this->heard($row['slots']),
            $row['dialogue_step'],
            $row['failure_class'],
        );
    }

    /**
     * Les créneaux entendus, résumés en une ligne : `item="du pain"`, et pour un créneau que le modèle sait résoudre, ce à quoi Amazon l'a rapproché
     * (`department="fruits et légumes" => fruit-et-legume`) ou le fait qu'il ne l'a pas fait (`department="divers" (non reconnu)`) : c'est ce qui
     * dit pourquoi une réponse n'a pas été comprise. Un créneau sans texte entendu n'est pas écrit.
     */
    private function heard(?string $slotsJson): ?string
    {
        $slots = $slotsJson === null ? null : json_decode($slotsJson, true);
        if (!is_array($slots)) {
            return null;
        }

        $parts = [];
        foreach ($slots as $name => $slot) {
            if (!is_array($slot) || !is_string($slot['value'] ?? null)) {
                continue;
            }
            $part = sprintf('%s="%s"', $name, $slot['value']);

            $resolution = $this->resolution($slot['resolutions'] ?? null);
            if ($resolution !== null) {
                $part .= $resolution;
            }
            $parts[] = $part;
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * ` => id` quand une autorité a rapproché la valeur d'une des nôtres, ` (non reconnu)` quand une autorité a répondu qu'elle n'en reconnaît aucune,
     * `null` quand le créneau n'a aucune résolution (un nombre, du texte libre).
     */
    private function resolution(mixed $resolutions): ?string
    {
        $authorities = is_array($resolutions) && is_array($resolutions['resolutionsPerAuthority'] ?? null) ? $resolutions['resolutionsPerAuthority'] : [];

        $noMatch = false;
        foreach ($authorities as $authority) {
            if (!is_array($authority)) {
                continue;
            }
            $code = is_array($authority['status'] ?? null) ? ($authority['status']['code'] ?? null) : null;
            if ($code === 'ER_SUCCESS_NO_MATCH') {
                $noMatch = true;
            }
            if ($code !== 'ER_SUCCESS_MATCH' || !is_array($authority['values'] ?? null)) {
                continue;
            }
            foreach ($authority['values'] as $value) {
                $id = is_array($value) && is_array($value['value'] ?? null) ? ($value['value']['id'] ?? null) : null;
                if (is_string($id) && $id !== '') {
                    return ' => ' . $id;
                }
            }
        }

        return $noMatch ? ' (non reconnu)' : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodedObject(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
