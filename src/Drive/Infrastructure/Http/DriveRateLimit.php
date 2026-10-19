<?php

namespace Repas\Drive\Infrastructure\Http;


/**
 * Les deux plafonds de débit par utilisateur des routes du drive (DRV-615). La valeur est le nom du limiteur déclaré dans
 * `config/packages/rate_limiter.yaml` (`limiter.drive_write`, `limiter.drive_read`), où se trouvent les chiffres.
 */
enum DriveRateLimit: string
{
    /** `PUT /drive/settings`, `PUT /drive/choices`, `PUT` et `DELETE /drive/choices/{slug}` : un compteur commun. */
    case WRITE = 'drive_write';

    /** `GET /drive/plans/{id}` et `GET /drive/estimates/{id}`, les lectures qui coûtent 4 ou 5 requêtes SQL (le plan en lit une de plus depuis DRV-642) : un compteur commun. */
    case READ = 'drive_read';
}
