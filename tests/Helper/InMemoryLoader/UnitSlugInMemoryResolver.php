<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Shared\Application\Interface\UnitSlugResolver;

/**
 * Double idiot : renvoie les unités programmées telles quelles (aucun filtre sur les slugs demandés, c'est au handler de comparer) et
 * enregistre ses appels.
 */
class UnitSlugInMemoryResolver implements UnitSlugResolver
{
    /** @var list<list<string>> slugs reçus à chaque appel, dans l'ordre des appels */
    public array $existingSlugsCalls = [];

    /**
     * @param list<string> $units slugs des unités qui existent
     */
    public function __construct(
        private readonly array $units = [],
    ) {
    }

    public function existingSlugs(array $slugs): array
    {
        $this->existingSlugsCalls[] = $slugs;

        return $this->units;
    }
}
