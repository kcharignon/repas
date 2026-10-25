<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use DateTimeImmutable;
use Repas\Voice\Application\Loader\AccessGrantLoader;
use Repas\Voice\Application\Query\ResolveAccessGrant\ResolvedAccessGrantDTO;

/**
 * Double idiot : renvoie le DTO programmé (ou rien) et enregistre les hachés et les dates demandés. Il ne juge ni l'expiration ni la
 * liaison : c'est le travail du chargeur réel, prouvé sur la vraie base.
 */
class AccessGrantInMemoryLoader implements AccessGrantLoader
{
    /** @var list<array{string, DateTimeImmutable}> */
    public array $asked = [];

    public function __construct(private readonly ?ResolvedAccessGrantDTO $grant = null)
    {
    }

    public function resolve(string $accessHash, DateTimeImmutable $now): ?ResolvedAccessGrantDTO
    {
        $this->asked[] = [$accessHash, $now];

        return $this->grant;
    }
}
