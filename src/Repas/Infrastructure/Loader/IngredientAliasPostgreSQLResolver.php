<?php

namespace Repas\Repas\Infrastructure\Loader;

use Repas\Repas\Domain\Interface\IngredientAliasRepository;
use Repas\Shared\Application\Interface\IngredientAliasResolver;

/**
 * Dans `Infrastructure/Loader` comme les autres résolveurs de `Shared` ; `Resolver` et non `Loader` pour la raison dite sur le port.
 * La requête est celle des alias de Repas (`IngredientAliasRepository::resolve()`, une seule lecture qui ne rend que les alias
 * valides) : la définition d'un alias valide n'est écrite qu'une fois.
 */
final readonly class IngredientAliasPostgreSQLResolver implements IngredientAliasResolver
{
    public function __construct(
        private IngredientAliasRepository $aliasRepository,
    ) {
    }

    public function resolve(string $userId, array $slugs): array
    {
        return $this->aliasRepository->resolve($userId, $slugs);
    }
}
