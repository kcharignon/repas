<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Shared\Application\Interface\IngredientNameLoader;

/**
 * Double idiot : renvoie les noms programmés tels quels (ni filtre sur les slugs demandés) et enregistre ses appels.
 */
class IngredientNameInMemoryLoader implements IngredientNameLoader
{
    /** @var list<list<string>> slugs reçus à chaque appel, dans l'ordre des appels */
    public array $namesBySlugCalls = [];

    /**
     * @param array<string, string> $names nom par slug
     */
    public function __construct(
        private readonly array $names = [],
    ) {
    }

    public function namesBySlug(array $slugs): array
    {
        $this->namesBySlugCalls[] = $slugs;

        return $this->names;
    }
}
