<?php

namespace Repas\Tests\Helper;


use Repas\Repas\Infrastructure\Network\HostAddressResolver;

/**
 * Un résolveur de noms qui rend les adresses qu'on lui donne et compte ses appels : aucun accès au DNS dans les tests du lecteur de
 * page Marmiton ni du téléchargement d'images (ADR 0039). `resolve` et `resolveAll` rendent les adresses données telles quelles
 * (une adresse invalide aussi : c'est le lecteur qui doit la refuser). `forHost` donne des adresses propres à un nom (une redirection vers un autre hôte).
 */
final class FixedHostAddressResolver implements HostAddressResolver
{
    /** @var list<string> les noms demandés, dans l'ordre */
    public array $asked = [];
    /** @var array<string, list<string>> les adresses d'un nom précis, à la place des adresses par défaut */
    private array $byHost = [];

    /**
     * @param list<string> $addresses
     */
    public function __construct(private readonly array $addresses)
    {
    }

    /**
     * @param list<string> $addresses
     */
    public function forHost(string $host, array $addresses): self
    {
        $this->byHost[$host] = $addresses;

        return $this;
    }

    public function resolve(string $host): array
    {
        return $this->resolveAll($host);
    }

    public function resolveAll(string $host): array
    {
        $this->asked[] = $host;

        return $this->byHost[$host] ?? $this->addresses;
    }
}
