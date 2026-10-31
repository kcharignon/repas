<?php

namespace Repas\Voice\Application\Query\ResolveAccessGrant;


use Psr\Clock\ClockInterface;
use Repas\Voice\Application\Port\ActiveUserQueryPort;
use Repas\Voice\Application\Loader\AccessGrantLoader;
use Repas\Voice\Domain\Model\OpaqueToken;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Résout un jeton d'accès Alexa en compte et en liaison (ADR 0029 décision 6, pour VOX-204), ou en rien : il faut un jeton de la forme
 * des nôtres (un JWT, par exemple, n'en est pas un), connu par son haché, non expiré, d'une liaison non révoquée, ET un compte ACTIF
 * (ADR 0021 : un compte désactivé, en attente ou supprimé ne peut rien par la voix). Une seule lecture ; ne modifie rien (la date
 * de dernier usage est écrite par le webhook, pas par cette lecture).
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class ResolveAccessGrantQueryHandler
{
    public function __construct(
        private AccessGrantLoader $accessGrantLoader,
        private ActiveUserQueryPort $activeUserChecker,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ResolveAccessGrantQuery $query): ?ResolvedAccessGrantDTO
    {
        if (!OpaqueToken::isWellFormed($query->accessToken)) {
            return null;
        }

        $grant = $this->accessGrantLoader->resolve(OpaqueToken::hash($query->accessToken), $this->clock->now());

        return $grant !== null && $this->activeUserChecker->isActive($grant->userId) ? $grant : null;
    }
}
