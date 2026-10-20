<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Shared\Application\Interface\IngredientAliasResolver;

/**
 * Double idiot : renvoie les alias programmés tels quels (aucun filtre sur les slugs demandés, c'est au handler de lire ce qui l'intéresse)
 * et enregistre ses appels. Sans programmation, c'est un utilisateur qui n'a aucun alias.
 */
class IngredientAliasInMemoryResolver implements IngredientAliasResolver
{
    /** @var list<array{string, list<string>}> utilisateur et slugs reçus à chaque appel, dans l'ordre des appels */
    public array $resolveCalls = [];

    /**
     * @param array<string, string> $aliases le slug de l'ingrédient de l'utilisateur par slug d'alias
     */
    public function __construct(
        private readonly array $aliases = [],
    ) {
    }

    public function resolve(string $userId, array $slugs): array
    {
        $this->resolveCalls[] = [$userId, $slugs];

        return $this->aliases;
    }
}
