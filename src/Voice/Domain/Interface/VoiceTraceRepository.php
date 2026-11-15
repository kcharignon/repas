<?php

namespace Repas\Voice\Domain\Interface;


use Repas\Voice\Domain\Model\VoiceTrace;

/**
 * La trace des échanges avec Alexa, côté écriture (ADR 0029 décision 14). Elle ne se lit que par le chargeur `VoiceTraceLoader`.
 */
interface VoiceTraceRepository
{
    /**
     * Ajoute l'échange puis ne garde que les `VoiceTrace::RETENTION` plus récents : une insertion et une suppression bornée, quel que soit le nombre de
     * lignes (jamais une boucle).
     */
    public function add(VoiceTrace $trace): void;
}
