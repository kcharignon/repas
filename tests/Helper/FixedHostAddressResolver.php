<?php

namespace Repas\Tests\Helper;


use Repas\Repas\Infrastructure\Marmiton\HostAddressResolver;

/**
 * Un résolveur de noms qui rend les adresses qu'on lui donne et compte ses appels : aucun accès au DNS dans les tests du lecteur de
 * page Marmiton.
 */
final class FixedHostAddressResolver implements HostAddressResolver
{
    /** @var list<string> les noms demandés, dans l'ordre */
    public array $asked = [];

    /**
     * @param list<string> $addresses
     */
    public function __construct(private readonly array $addresses)
    {
    }

    public function resolve(string $host): array
    {
        $this->asked[] = $host;

        return $this->addresses;
    }
}
