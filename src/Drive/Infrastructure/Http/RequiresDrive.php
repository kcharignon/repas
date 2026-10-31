<?php

namespace Repas\Drive\Infrastructure\Http;


use Attribute;

/**
 * Marque un controller (classe ou méthode) qui n'existe que si l'interrupteur du drive est allumé : coupé, la route
 * répond `404 DRIVE_DISABLED` avant tout autre traitement (voir `DriveEnabledListener`).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class RequiresDrive
{
}
