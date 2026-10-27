<?php

namespace Repas\Shared\Infrastructure\RateLimiter;


use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * Les verrous des compteurs de débit (`lock_factory` de config/packages/rate_limiter.yaml) : chaque lecture-écriture d'un compteur se
 * fait sous verrou, de sorte que deux requêtes simultanées comptent chacune.
 *
 * Un limiteur demande un verrou par clé de compteur (une clé par adresse, par adresse et e-mail, par e-mail saisi…). Avec le magasin
 * `flock`, chaque ressource demandée est un fichier, jamais effacé : leur nombre suivrait celui des clés rencontrées. Cette fabrique ramène
 * la ressource à l'un de {@see self::SLOTS} verrous par un hachage stable : le nombre de fichiers est borné, et deux clés qui partagent un
 * verrou s'attendent l'une l'autre le temps d'une lecture-écriture du cache (le verrou est tenu quelques centaines de microsecondes).
 *
 * Règle : on ne prend jamais un verrou de cette fabrique à l'intérieur d'un autre verrou de cette fabrique (deux ressources du même
 * verrou s'attendraient pour toujours). Une section critique plus longue (la comparaison d'un mot de passe, qui prend des centaines de
 * millisecondes) prend son verrou à la fabrique ordinaire du framework (`lock.factory`), qui a un verrou par ressource.
 */
final class BoundedLockFactory extends LockFactory
{
    /** Nombre de verrous distincts, donc de fichiers avec le magasin `flock`. */
    public const int SLOTS = 256;

    public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
    {
        return parent::createLock(self::slotOf($resource), $ttl, $autoRelease);
    }

    /**
     * Le verrou qui garde cette ressource : toujours le même pour une même ressource, d'un processus à l'autre.
     */
    public static function slotOf(string $resource): string
    {
        return sprintf('limiter-slot-%03d', crc32($resource) % self::SLOTS);
    }
}
