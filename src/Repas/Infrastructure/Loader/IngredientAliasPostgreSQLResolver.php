<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Domain\Interface\IngredientAliasRepository;
use Repas\Repas\Infrastructure\Visibility\IngredientVisibility;
use Repas\Shared\Application\Interface\IngredientAliasResolver;

/**
 * Dans `Infrastructure/Loader` comme les autres résolveurs de `Shared` ; `Resolver` et non `Loader` pour la raison dite sur le port.
 * `resolve()` pose la requête des alias de Repas (`IngredientAliasRepository::resolve()`, une seule lecture qui ne rend que les alias
 * valides) : la définition d'un alias valide n'est écrite qu'une fois. `resolveOwned()` y ajoute l'ingrédient lui-même, à l'utilisateur
 * (`IngredientVisibility`), dans la même lecture.
 */
final readonly class IngredientAliasPostgreSQLResolver implements IngredientAliasResolver
{
    public function __construct(
        private IngredientAliasRepository $aliasRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function resolve(string $userId, array $slugs): array
    {
        return $this->aliasRepository->resolve($userId, $slugs);
    }

    public function resolveOwned(string $userId, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        // Un alias valide désigne un ingrédient qui existe et qui est à l'utilisateur (comme `IngredientAliasRepository::resolve()`) ; il
        // l'emporte sur le slug reçu. Sans alias, le slug répond s'il est lui-même un ingrédient de l'utilisateur. Rien d'autre.
        /** @var array<string, string> $resolved */
        $resolved = $this->entityManager->getConnection()->fetchAllKeyValue(
            'SELECT s.slug, COALESCE(target.slug, own.slug)'
            . ' FROM (SELECT DISTINCT j.slug FROM jsonb_array_elements_text(CAST(:slugs AS jsonb)) AS j(slug)) s'
            . ' LEFT JOIN ingredient_alias a ON a.user_id = :user AND a.alias_slug = s.slug'
            . ' LEFT JOIN ingredient target ON target.slug = a.ingredient_slug AND ' . IngredientVisibility::ownedSql('target', 'user')
            . ' LEFT JOIN ingredient own ON own.slug = s.slug AND ' . IngredientVisibility::ownedSql('own', 'user')
            . ' WHERE target.slug IS NOT NULL OR own.slug IS NOT NULL',
            ['slugs' => json_encode(array_values(array_unique($slugs)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'user' => $userId],
        );

        return $resolved;
    }
}
