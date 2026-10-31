<?php

namespace Repas\Drive\Infrastructure\Acl;

use Repas\Drive\Application\Port\IngredientAliasCommandPort;
use Repas\Repas\Application\OpenHostService\IngredientAliasCommandOHS;

/**
 * Couche anticorruption (ADR 0035) : la traduction des slugs d'ingrédients que le contexte `Repas` publie pour qui écrit, telle quelle (des
 * identifiants de part et d'autre).
 */
final readonly class IngredientAliasCommandAdapter implements IngredientAliasCommandPort
{
    public function __construct(
        private IngredientAliasCommandOHS $aliases,
    ) {
    }

    public function resolve(string $userId, array $slugs): array
    {
        return $this->aliases->resolve($userId, $slugs);
    }

    public function resolveOwned(string $userId, array $slugs): array
    {
        return $this->aliases->resolveOwned($userId, $slugs);
    }
}
