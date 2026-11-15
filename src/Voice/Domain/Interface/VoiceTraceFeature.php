<?php

namespace Repas\Voice\Domain\Interface;


/**
 * Interrupteur de la trace des échanges avec Alexa (ADR 0029 décision 14, amendement du 2026-11-15).
 *
 * Coupé par défaut (variable `ALEXA_TRACE_ENABLED`, y compris quand elle est absente ou vide) : tant qu'il l'est, le webhook n'écrit AUCUNE ligne
 * de trace et ne coûte aucune requête SQL de plus. L'allumer est une décision humaine, par un script (`ecrire-alexa-trace.sh`), le temps de la
 * phase de test ; il est indépendant de `ALEXA_ENABLED` (couper la commande vocale ne vide pas la table, la lire marche toujours).
 */
interface VoiceTraceFeature
{
    public function isEnabled(): bool;
}
