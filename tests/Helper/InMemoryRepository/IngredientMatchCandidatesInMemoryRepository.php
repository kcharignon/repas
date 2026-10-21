<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Interface\IngredientMatchCandidates;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientCandidate;
use Repas\Repas\Domain\Model\Unit;

/**
 * Double en mémoire du port : mêmes candidats que la requête réelle (les ingrédients de l'utilisateur, et seulement les siens), triés par
 * slug. Que la règle de visibilité soit la bonne sur la base se prouve dans `IngredientMatchCandidatesRepositoryTest`. Il compte ses
 * lectures : une copie de recette n'en fait jamais plus d'une.
 */
class IngredientMatchCandidatesInMemoryRepository implements IngredientMatchCandidates
{
    public int $reads = 0;

    public function __construct(
        private readonly IngredientInMemoryRepository $ingredients,
    ) {
    }

    public function visibleTo(string $userId): array
    {
        ++$this->reads;
        $visible = $this->ingredients->findAll()->filter(
            static fn(Ingredient $ingredient): bool => $ingredient->getCreatorId() === $userId,
        )->toArray();
        usort($visible, static fn(Ingredient $first, Ingredient $second): int => $first->getSlug() <=> $second->getSlug());

        return array_map(
            static fn(Ingredient $ingredient): IngredientCandidate => new IngredientCandidate(
                slug: $ingredient->getSlug(),
                name: $ingredient->getName(),
                departmentSlug: $ingredient->getDepartment()->getSlug(),
                purchaseUnitSlug: $ingredient->getDefaultPurchaseUnit()->getSlug(),
                compatibleUnitSlugs: array_values($ingredient->getCompatibleUnits()->map(static fn(Unit $unit): string => $unit->getSlug())->toArray()),
                adoptedFrom: $ingredient->getAdoptedFrom(),
            ),
            $visible,
        );
    }
}
