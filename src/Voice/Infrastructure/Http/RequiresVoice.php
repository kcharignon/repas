<?php

namespace Repas\Voice\Infrastructure\Http;


use Attribute;

/**
 * Marque un controller (classe ou méthode) qui n'existe que si l'interrupteur de la commande vocale est allumé : coupé, la route
 * répond `404 VOICE_DISABLED` avant tout autre traitement (voir `VoiceEnabledListener`).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class RequiresVoice
{
}
