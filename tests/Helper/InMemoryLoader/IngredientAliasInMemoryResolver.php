<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Shared\Application\Interface\IngredientAliasResolver;

/**
 * Double idiot : renvoie les alias programmés tels quels (aucun filtre sur les slugs demandés, c'est au handler de lire ce qui l'intéresse)
 * et enregistre ses appels. Sans programmation, c'est un utilisateur qui n'a aucun alias et dont tous les slugs sont les siens.
 */
class IngredientAliasInMemoryResolver implements IngredientAliasResolver
{
    /** @var list<array{string, list<string>}> utilisateur et slugs reçus à chaque appel (`resolve()` ou `resolveOwned()`), dans l'ordre des appels */
    public array $resolveCalls = [];

    /**
     * @param array<string, string> $aliases le slug de l'ingrédient de l'utilisateur par slug d'alias
     * @param list<string> $notOwned les slugs qui ne sont à personne de l'utilisateur (inconnus, ou à un autre compte) : `resolveOwned()` les omet
     */
    public function __construct(
        private readonly array $aliases = [],
        private readonly array $notOwned = [],
    ) {
    }

    public function resolve(string $userId, array $slugs): array
    {
        $this->resolveCalls[] = [$userId, $slugs];

        return $this->aliases;
    }

    public function resolveOwned(string $userId, array $slugs): array
    {
        $this->resolveCalls[] = [$userId, $slugs];

        $owned = [];
        foreach ($slugs as $slug) {
            if (!in_array($slug, $this->notOwned, true)) {
                $owned[$slug] = $this->aliases[$slug] ?? $slug;
            }
        }

        return $owned;
    }
}
