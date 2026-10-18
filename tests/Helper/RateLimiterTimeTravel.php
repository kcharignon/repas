<?php

namespace Repas\Tests\Helper;


use LogicException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\RateLimiter\Policy\SlidingWindow;

/**
 * Fait « passer le temps » pour un compteur de débit sans dormir.
 *
 * Le composant RateLimiter de Symfony lit l'heure avec `microtime()` : on ne peut pas lui injecter d'horloge. Mais l'état d'une
 * fenêtre glissante (`SlidingWindow`) est rangé dans le cache et contient sa date de fin : la reculer de N secondes revient à
 * avancer le temps de N secondes pour ce compteur. À utiliser avec le même pool que le limiteur (`CacheStorage`), c'est le
 * « stockage injecté » des tests de débit. Une fenêtre glissante se vide complètement deux intervalles après le dernier coup.
 */
final readonly class RateLimiterTimeTravel
{
    public function __construct(private CacheItemPoolInterface $pool)
    {
    }

    /**
     * @param string $limiterName nom du limiteur (`drive_write`)
     * @param string $key         clé passée à `create()` (`user-<id>`)
     */
    public function advance(string $limiterName, string $key, int $seconds): void
    {
        $item = $this->pool->getItem(sha1($limiterName . '-' . $key));
        $state = $item->get();
        if (!$state instanceof SlidingWindow) {
            throw new LogicException("Aucun compteur pour $limiterName-$key : rien à faire avancer.");
        }

        $data = $state->__serialize();
        $data[array_key_first($data)] -= $seconds;
        $state->__unserialize($data);

        $item->set($state);
        $this->pool->save($item);
    }
}
