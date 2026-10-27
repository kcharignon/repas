<?php

/**
 * Un processus d'essai des compteurs de débit sous charge simultanée (tests/Shared/Infrastructure/RateLimiter) : même cache de fichiers,
 * mêmes verrous `flock` que les autres processus lancés par le test. Il annonce qu'il est prêt, attend le signal de départ, puis fait ses
 * demandes et écrit son décompte en JSON.
 *
 * Arguments : <scénario> <répertoire> <numéro> <demandes>
 * - `tokens` : demande un jeton d'un compteur de 20 par heure, `<demandes>` fois ; écrit {"accepted", "refused"}.
 * - `password` : tente `<demandes>` fois de comparer un mot de passe refusé pour le même utilisateur (5 refus par 15 minutes, comparaison
 *   de 20 ms) ; écrit {"compared", "refused"}.
 */

use Repas\Shared\Domain\Exception\RateLimitedException;
use Repas\Shared\Infrastructure\RateLimiter\BoundedLockFactory;
use Repas\User\Infrastructure\Security\PasswordAttemptRateLimiter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $scenario, $directory, $number, $requests] = $_SERVER['argv'];

$storage = new CacheStorage(new FilesystemAdapter('limiters', 0, $directory . '/cache'));
$store = new FlockStore($directory . '/locks');

touch($directory . '/ready-' . $number);
while (!is_file($directory . '/go')) {
    usleep(200);
}

if ($scenario === 'tokens') {
    $factory = new RateLimiterFactory(
        ['id' => 'essai', 'policy' => 'fixed_window', 'limit' => 20, 'interval' => '1 hour'],
        $storage,
        new BoundedLockFactory($store),
    );
    $accepted = 0;
    $refused = 0;
    for ($i = 0; $i < (int) $requests; $i++) {
        $factory->create('cle')->consume()->isAccepted() ? $accepted++ : $refused++;
    }
    echo json_encode(['accepted' => $accepted, 'refused' => $refused], JSON_THROW_ON_ERROR);

    exit(0);
}

if ($scenario === 'password') {
    $limiter = new PasswordAttemptRateLimiter(
        new RateLimiterFactory(
            ['id' => 'password_check', 'policy' => 'fixed_window', 'limit' => 5, 'interval' => '15 minutes'],
            $storage,
            new BoundedLockFactory($store),
        ),
        new LockFactory($store),
    );
    $compared = 0;
    $refused = 0;
    for ($i = 0; $i < (int) $requests; $i++) {
        try {
            $limiter->attempt('utilisateur', static function () use (&$compared): bool {
                usleep(20_000);
                $compared++;

                return false;
            });
        } catch (RateLimitedException) {
            $refused++;
        }
    }
    echo json_encode(['compared' => $compared, 'refused' => $refused], JSON_THROW_ON_ERROR);

    exit(0);
}

fwrite(STDERR, "Scénario inconnu : $scenario\n");
exit(2);
