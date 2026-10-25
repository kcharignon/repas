<?php

namespace Repas\Voice\Application\Loader;


use DateTimeImmutable;
use Repas\Voice\Application\Query\ResolveAccessGrant\ResolvedAccessGrantDTO;

interface AccessGrantLoader
{
    /**
     * Le compte et la liaison d'un jeton d'accès, d'après son HACHÉ : le jeton existe, n'est pas expiré (`$now` est avant son
     * expiration), et sa liaison n'est pas révoquée et appartient bien à son compte. Un jeton dont le jeton de rafraîchissement a
     * été échangé reste valable jusqu'à sa propre expiration. `null` sinon.
     */
    public function resolve(string $accessHash, DateTimeImmutable $now): ?ResolvedAccessGrantDTO;
}
