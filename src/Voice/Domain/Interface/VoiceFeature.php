<?php

namespace Repas\Voice\Domain\Interface;


/**
 * Interrupteur de la commande vocale Alexa (ADR 0029 décision 8, docs/voice/roadmap.md).
 *
 * Coupé par défaut (variable `ALEXA_ENABLED`, y compris quand elle est absente) : tant qu'il l'est, le webhook d'Alexa, l'autorisation
 * de la liaison de compte et l'échange des jetons répondent `404 VOICE_DISABLED` avant tout traitement. L'allumer est une décision
 * humaine, par un script, après un déploiement stable.
 */
interface VoiceFeature
{
    public function isEnabled(): bool;
}
