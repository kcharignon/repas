<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\ListRoutingRepository;
use Repas\Repas\Domain\Model\ProductPlacement;
use Repas\Repas\Domain\Service\ListDestinationResolver;

/**
 * Double en mémoire du port : il lit les produits et les types des deux autres doubles, avec la même règle que la requête SQL (seuls les
 * types du propriétaire existent, un produit inconnu ou à un autre va dans la liste principale) et le vrai `ListDestinationResolver`.
 */
class ListRoutingInMemoryRepository implements ListRoutingRepository
{
    public int $reads = 0;

    public function __construct(
        private readonly IngredientInMemoryRepository $ingredients,
        private readonly ShoppingListTypeInMemoryRepository $listTypes,
    ) {
    }

    public function destinationsFor(string $ownerId, array $slugs): array
    {
        $slugs = array_values(array_unique($slugs));
        if ($slugs === []) {
            return [];
        }

        $destinations = array_fill_keys($slugs, null);
        foreach ($this->placementsFor($ownerId, $slugs) as $slug => $placement) {
            $destinations[$slug] = $placement->listTypeId;
        }

        return $destinations;
    }

    public function placementsFor(string $ownerId, array $slugs): array
    {
        $slugs = array_values(array_unique($slugs));
        if ($slugs === []) {
            return [];
        }
        $this->reads++;

        $ownerTypes = array_filter($this->listTypes->all(), static fn($type): bool => $type->getOwnerId() === $ownerId);
        $existing = array_values(array_map(static fn($type): string => $type->getId(), $ownerTypes));
        $resolver = new ListDestinationResolver();

        $placements = [];
        foreach ($slugs as $slug) {
            try {
                $ingredient = $this->ingredients->findOneBySlug($slug);
            } catch (IngredientException) {
                continue;
            }
            if ($ingredient->getCreatorId() !== $ownerId) {
                continue;
            }
            $departmentType = null;
            foreach ($ownerTypes as $type) {
                if (in_array($ingredient->getDepartment()->getSlug(), $type->getDepartmentSlugs(), true)) {
                    $departmentType = $type->getId();
                }
            }
            $placements[$slug] = new ProductPlacement(
                $resolver->resolve($ingredient->getListDestination(), $departmentType, $existing),
                $ingredient->getDefaultPurchaseUnit()->getSlug(),
            );
        }

        return $placements;
    }
}
