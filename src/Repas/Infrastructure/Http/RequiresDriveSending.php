<?php

namespace Repas\Repas\Infrastructure\Http;

use Attribute;

/**
 * Marque un controller de `Repas` qui n'existe que si le drive est allumé (l'envoi d'une liste de courses) : coupé, la route répond
 * `404 DRIVE_DISABLED` avant tout autre traitement (voir `DriveSendingEnabledListener`).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class RequiresDriveSending
{
}
