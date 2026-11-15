<?php

namespace Repas\Voice\Application\Loader;


use DateTimeImmutable;
use Repas\Voice\Application\Query\GetVoiceTrace\VoiceTraceDetailDTO;
use Repas\Voice\Application\Query\ListVoiceTraces\VoiceTraceSummaryDTO;

/**
 * La trace des échanges avec Alexa, côté lecture (ADR 0029 décision 14). Lecture seule : le chargeur n'écrit jamais.
 */
interface VoiceTraceLoader
{
    /** Le plus d'échanges qu'une lecture rend. */
    public const int MAX_LIMIT = 200;

    /**
     * Les échanges les plus récents d'abord (numéro décroissant), au plus `$limit` (borné de 1 à `MAX_LIMIT`).
     *
     * @param ?string $intent seulement cette intention (nom exact), `null` : toutes
     * @param bool $failedOnly seulement les échanges dont l'issue est une panne
     * @param ?DateTimeImmutable $since seulement ceux enregistrés à cet instant ou après
     *
     * @return list<VoiceTraceSummaryDTO>
     */
    public function recent(int $limit, ?string $intent, bool $failedOnly, ?DateTimeImmutable $since): array;

    /**
     * Un échange en entier, ou `null` si ce numéro n'existe pas (ou n'existe plus : seuls les plus récents sont gardés).
     */
    public function one(int $id): ?VoiceTraceDetailDTO;
}
